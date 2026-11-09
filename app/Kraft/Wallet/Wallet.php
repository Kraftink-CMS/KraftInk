<?php

namespace App\Kraft\Wallet;

use App\Models\Setting;
use App\Events\WalletChanged;
use App\Kraft\Audit;
use App\Kraft\Currencies\CurrencyRegistry;
use App\Kraft\Settings;
use App\Models\Accounts\User;
use App\Models\Wallet\WalletAccount;
use App\Models\Wallet\WalletEntry;
use App\Models\Wallet\WalletTransaction;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class Wallet
{
    public function __construct(private Settings $settings)
    {
    }

    public function currency(): string
    {
        return $this->settings->get('wallet.currency', 'EUR');
    }

    public function balance(User|int $user): int
    {
        $account = WalletAccount::query()->find($this->id($user));

        return $account?->balance_minor ?? 0;
    }

    public function credit(
        User|int $user,
        int $amount,
        string $key,
        string $reason,
        array $context = [],
        ?User $actor = null,
    ): WalletTransaction {
        return $this->execute('credit', null, $this->id($user), $amount, $key, $reason, $context, $actor);
    }

    public function debit(
        User|int $user,
        int $amount,
        string $key,
        string $reason,
        array $context = [],
        ?User $actor = null,
    ): WalletTransaction {
        return $this->execute('debit', $this->id($user), null, $amount, $key, $reason, $context, $actor);
    }

    public function setBalance(
        User|int $user,
        int $target,
        string $key,
        string $reason,
        array $context = [],
        ?User $actor = null,
    ): WalletTransaction {
        return $this->execute('set', null, $this->id($user), $target, $key, $reason, $context, $actor);
    }

    public function transfer(User $from, User $to, int $amount, string $key, ?string $note = null): WalletTransaction
    {
        if (!$this->settings->get('wallet.transfers', false)) {
            throw ValidationException::withMessages(['amount' => __('site.account.wallet.transfers_disabled')]);
        }
        if ($from->id === $to->id) {
            throw ValidationException::withMessages(['recipient' => __('site.account.wallet.recipient_invalid')]);
        }

        return $this->execute(
            'transfer',
            $from->id,
            $to->id,
            $amount,
            $key,
            'wallet.transfer',
            ['note' => $note],
            $from,
        );
    }

    public function changeCurrency(string $currency): void
    {
        if ($currency === $this->currency()) {
            return;
        }
        app(CurrencyRegistry::class)->assertSupported($currency);
        DB::transaction(function () use ($currency) {
            Setting::query()->firstOrCreate(['key' => 'wallet.currency'], ['value' => $this->currency()]);
            Setting::query()->where('key', 'wallet.currency')->lockForUpdate()->first();
            if (
                WalletAccount::query()->where('balance_minor', '!=', 0)->exists() ||
                WalletTransaction::query()->exists()
            ) {
                throw ValidationException::withMessages(['currency' => __('site.account.wallet.currency_locked')]);
            }
            WalletAccount::query()->update(['currency' => $currency]);
            $this->settings->set('wallet.currency', $currency);
        }, 3);
    }

    private function execute(
        string $kind,
        ?int $from,
        ?int $to,
        int $amount,
        string $key,
        string $reason,
        array $context,
        ?User $actor,
    ): WalletTransaction {
        if (
            $amount < ($kind === 'set' ? 0 : 1) ||
            $amount > Money::MaxBalance ||
            !preg_match('/^[A-Za-z0-9][A-Za-z0-9_.:-]{5,139}$/D', $key) ||
            mb_strlen($reason) < 1 ||
            mb_strlen($reason) > 160 ||
            strlen(json_encode($context, JSON_THROW_ON_ERROR)) > 16384
        ) {
            throw ValidationException::withMessages(['amount' => __('site.account.wallet.invalid_amount')]);
        }
        $currency = $this->currency();
        $payloadHash = hash(
            'sha256',
            json_encode(
                [$kind, $from, $to, $amount, $currency, $reason, $this->canonical($context)],
                JSON_THROW_ON_ERROR,
            ),
        );

        return DB::transaction(
            function () use (
                $kind,
                $from,
                $to,
                $amount,
                $key,
                $reason,
                $context,
                $actor,
                $currency,
                $payloadHash,
            ) {
                $lockedCurrency = Setting::query()->where('key', 'wallet.currency')->sharedLock()->first();
                if (!$lockedCurrency || $lockedCurrency->value !== $currency) {
                    throw ValidationException::withMessages(['amount' => __('site.account.wallet.currency_changed')]);
                }
                $ids = array_values(array_unique(array_filter([$from, $to])));
                sort($ids);
                $accounts = User::query()->whereIn('id', $ids)->orderBy('id')->lockForUpdate()->get()->keyBy('id');
                if (
                    $accounts->count() !== count($ids) ||
                    $accounts->contains(
                        fn (User $user) => $user->anonymized_at ||
                            $user->import_locked ||
                            ($kind === 'transfer' && $user->isBanned()),
                    )
                ) {
                    throw ValidationException::withMessages([
                        'recipient' => __('site.account.wallet.account_unavailable'),
                    ]);
                }
                if ($existing = WalletTransaction::query()->where('operation_key', $key)->lockForUpdate()->first()) {
                    if (!hash_equals($existing->payload_hash, $payloadHash)) {
                        throw ValidationException::withMessages([
                            'amount' => __('site.account.wallet.operation_conflict'),
                        ]);
                    }

                    return $existing;
                }
                /** @var array<int, WalletAccount> $balances */
                $balances = [];
                /** @var User $account */
                foreach ($accounts as $account) {
                    $balance = WalletAccount::query()->find($account->id);
                    if (!$balance) {
                        $balance = WalletAccount::query()->create([
                            'user_id' => $account->id,
                            'balance_minor' => 0,
                            'currency' => $currency,
                        ]);
                    }
                    if ($balance->currency !== $currency) {
                        throw ValidationException::withMessages([
                            'amount' => __('site.account.wallet.currency_changed'),
                        ]);
                    }
                    $balances[$account->id] = $balance;
                    $this->opening($account, $balance, $currency);
                }
                if ($from && $balances[$from]->balance_minor < $amount) {
                    throw ValidationException::withMessages([
                        'amount' => __('site.account.wallet.insufficient_balance'),
                    ]);
                }
                if ($to && $kind !== 'set' && $balances[$to]->balance_minor + $amount > Money::MaxBalance) {
                    throw ValidationException::withMessages(['amount' => __('site.account.wallet.balance_limit')]);
                }
                $targetDelta = $kind === 'set' ? $amount - $balances[$to]->balance_minor : null;
                $movementAmount = $kind === 'set' ? abs($targetDelta) : $amount;
                $transaction = WalletTransaction::query()->create([
                    'operation_key' => $key,
                    'payload_hash' => $payloadHash,
                    'kind' => $kind,
                    'amount_minor' => $movementAmount,
                    'currency' => $currency,
                    'reason' => $reason,
                    'context' => $context,
                    'actor_id' => $actor?->id,
                    'created_at' => now(),
                ]);
                /** @var User $account */
                foreach ($accounts as $account) {
                    $before = $balances[$account->id]->balance_minor;
                    $delta = $targetDelta ?? ($account->id === $from ? -$amount : $amount);
                    $after = $before + $delta;
                    $balances[$account->id]->update(['balance_minor' => $after]);
                    $entry = WalletEntry::query()->create([
                        'transaction_id' => $transaction->id,
                        'user_id' => $account->id,
                        'amount_minor' => $delta,
                        'balance_before' => $before,
                        'balance_after' => $after,
                        'created_at' => now(),
                    ]);
                    event(new WalletChanged($account, $entry));
                }
                Audit::record(
                    'wallet.'.$kind,
                    [
                        'id' => $transaction->id,
                        'amount_minor' => $movementAmount,
                        'currency' => $currency,
                        'from' => $from,
                        'to' => $to,
                    ],
                    $actor,
                );

                return $transaction;
            },
            3,
        );
    }

    private function opening(User $user, WalletAccount $account, string $currency): void
    {
        $balance = $account->balance_minor;
        if ($balance === 0 || WalletEntry::query()->where('user_id', $user->id)->exists()) {
            return;
        }
        $key = 'opening:user:'.$user->id;
        $transaction = WalletTransaction::query()->create([
            'operation_key' => $key,
            'payload_hash' => hash('sha256', $key.':'.$balance.':'.$currency),
            'kind' => 'opening',
            'amount_minor' => $balance,
            'currency' => $currency,
            'reason' => 'wallet.opening',
            'created_at' => now(),
        ]);
        WalletEntry::query()->create([
            'transaction_id' => $transaction->id,
            'user_id' => $user->id,
            'amount_minor' => $balance,
            'balance_before' => 0,
            'balance_after' => $balance,
            'created_at' => now(),
        ]);
    }

    private function id(User|int $user): int
    {
        return $user instanceof User ? $user->id : $user;
    }

    private function canonical(array $context): array
    {
        if (!array_is_list($context)) {
            ksort($context);
        }
        foreach ($context as &$value) {
            if (is_array($value)) {
                $value = $this->canonical($value);
            }
        }

        return $context;
    }
}
