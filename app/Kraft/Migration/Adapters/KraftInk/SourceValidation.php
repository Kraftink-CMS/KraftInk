<?php

namespace App\Kraft\Migration\Adapters\KraftInk;

use App\Kraft\Migration\CoreMappings as BaseMappings;
use App\Kraft\Migration\ImportContext;
use App\Kraft\Wallet\Money;
use App\Kraft\Wallet\Wallet;
use Illuminate\Database\Connection;
use Illuminate\Support\Facades\DB;

final class SourceValidation
{
    public function __construct(private BaseMappings $core)
    {
    }

    public function validate(Connection $source, array $options, Adapter $adapter): array
    {
        if (!in_array('users', $options['tables'], true)) {
            return [];
        }
        $errors = [];
        $schema = $source->getSchemaBuilder();
        $context = new ImportContext(
            DB::connection(),
            '',
            $adapter,
            $options,
            fn (string $table) => $schema->hasTable($table)
                ? $source->table($table)->cursor()->map(fn (object $row) => (array) $row)
                : null,
        );
        if (
            $source
                ->table('users')
                ->where(fn ($query) => $query->where('account_type', '!=', 'human')->orWhereNull('account_type'))
                ->exists()
        ) {
            return [__('service_accounts.import_not_supported')];
        }
        $stored = $source->table('settings')->where('key', 'wallet.currency')->value('value');
        $currency = json_decode((string) $stored, true);
        if (!is_string($currency) || !preg_match('/^[A-Z]{3}$/D', $currency)) {
            return [__('system.imports.kraftink_invalid_currency')];
        }
        $accounts = $source
            ->table('wallet_accounts')
            ->join('users', 'users.id', '=', 'wallet_accounts.user_id')
            ->select(
                'users.*',
                'wallet_accounts.user_id',
                'wallet_accounts.balance_minor',
                'wallet_accounts.currency',
            );
        foreach ($accounts->cursor() as $account) {
            if ($this->preserved((array) $account, $options, $context)) {
                continue;
            }
            if (
                !ctype_digit((string) $account->balance_minor) ||
                $account->balance_minor > Money::MaxBalance ||
                !is_string($account->currency) ||
                !preg_match('/^[A-Z]{3}$/D', $account->currency) ||
                ($account->balance_minor > 0 && $account->currency !== $currency)
            ) {
                $errors[] = __('system.imports.kraftink_wallet_mismatch', ['id' => $account->user_id]);
            }
            if ($account->balance_minor > 0 && $currency !== app(Wallet::class)->currency()) {
                $errors[] = __('system.imports.kraftink_currency', [
                    'source' => $currency,
                    'target' => app(Wallet::class)->currency(),
                ]);
            }
        }

        return array_values(array_unique($errors));
    }

    private function preserved(array $user, array $options, ImportContext $context): bool
    {
        return ($options['existing_users'] ?? 'reject') === 'preserve' &&
            $this->core->accountMatches($context->adapter->user($user, $context), DB::connection())->count() === 1;
    }
}
