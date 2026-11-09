<?php

namespace App\Http\Controllers\Account;

use App\Kraft\System\TableQuery;
use App\Http\Controllers\Controller;
use App\Kraft\Audit;
use App\Kraft\Auth\AccountIdentity;
use App\Kraft\Currencies\CurrencyRegistry;
use App\Kraft\Settings;
use App\Kraft\System\SensitiveAction;
use App\Kraft\Wallet\Money;
use App\Kraft\Wallet\Wallet;
use App\Models\Accounts\User;
use App\Models\Wallet\WalletEntry;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;

class WalletController extends Controller
{
    public function account(Request $request, Wallet $wallet): View
    {
        return view('default.user.account.wallet.index', [
            'balance' => $wallet->balance($request->user()),
            'currency' => $wallet->currency(),
            'entries' => TableQuery::paginate(
                WalletEntry::query()->where('user_id', $request->user()->id)->with('transaction')->latest('id'),
                ['created_at', 'amount_minor', 'balance_after'],
            ),
            'requestId' => (string) Str::uuid(),
        ]);
    }

    public function transfer(Request $request, Wallet $wallet): RedirectResponse
    {
        $data = $request->validate([
            'recipient' => 'required|string|max:40',
            'amount' => 'required|string|max:30',
            'note' => 'nullable|string|max:255',
            'request_id' => 'required|uuid',
        ]);
        $amount = Money::parse($data['amount']);
        $recipient = User::query()
            ->whereRaw(AccountIdentity::expression().' = ?', [mb_strtolower(trim($data['recipient']))])
            ->whereNull('anonymized_at')
            ->first();
        if (!$recipient || $recipient->id === $request->user()->id) {
            throw ValidationException::withMessages(['recipient' => __('site.account.wallet.recipient_invalid')]);
        }
        app(SensitiveAction::class)->confirm($request);
        $wallet->transfer(
            $request->user(),
            $recipient,
            $amount,
            'transfer:'.$request->user()->id.':'.$data['request_id'],
            $data['note'] ?? null,
        );

        return back()->with('status', __('site.account.wallet.completed'));
    }

    public function admin(Request $request, Wallet $wallet, Settings $settings): View
    {
        $query = User::with('walletAccount')->whereNull('anonymized_at');
        if ($request->filled('q')) {
            $search = trim($request->string('q')->limit(160)->toString());
            $query->where(function ($query) use ($search) {
                TableQuery::whereContains($query, ['username', 'email'], $search);
                if (ctype_digit(ltrim($search, '#'))) {
                    $query->orWhere('id', ltrim($search, '#'));
                }
            });
        }
        $selectedId = $request->session()->getOldInput('user_id', $request->input('member'));
        $selectedMember =
            is_scalar($selectedId) && ctype_digit((string) $selectedId)
                ? User::with('walletAccount')->whereNull('anonymized_at')->find($selectedId)
                : null;

        return view('admin.wallet.index', [
            'members' => TableQuery::paginate($query->latest('id'), ['username']),
            'selectedMember' => $selectedMember,
            'currencies' => app(CurrencyRegistry::class)->all(),
            'currency' => $wallet->currency(),
            'transfers' => (bool) $settings->get('wallet.transfers', false),
            'entries' => TableQuery::paginate(
                WalletEntry::with(['user:id,username', 'transaction'])->latest('id'),
                ['created_at', 'amount_minor', 'balance_after'],
                pageName: 'history_page',
            ),
            'requestId' => (string) Str::uuid(),
        ]);
    }

    public function adjust(Request $request, Wallet $wallet): RedirectResponse
    {
        try {
            $data = $request->validate([
                'user_id' => 'required|integer|exists:users,id',
                'direction' => ['required', Rule::in(['credit', 'debit', 'set'])],
                'amount' => 'required|string|max:30',
                'reason' => 'required|string|max:160',
                'request_id' => 'required|uuid',
            ]);
            $amount = Money::parse($data['amount']);
            app(SensitiveAction::class)->confirm($request);
            $method = $data['direction'] === 'set' ? 'setBalance' : $data['direction'];
            $wallet->{$method}(
                (int) $data['user_id'],
                $amount,
                'adjust:'.$request->user()->id.':'.$data['request_id'],
                $data['reason'],
                [],
                $request->user(),
            );
        } catch (ValidationException $exception) {
            $exception->errorBag = 'walletAdjustment';
            throw $exception;
        }

        return back()->with('status', __('site.account.wallet.completed'));
    }

    public function configure(Request $request, Wallet $wallet, Settings $settings): RedirectResponse
    {
        try {
            $data = $request->validate([
                'currency' => ['required', Rule::in([...app(CurrencyRegistry::class)->codes(), $wallet->currency()])],
                'transfers' => 'required|boolean',
            ]);
            $wallet->changeCurrency($data['currency']);
        } catch (ValidationException $exception) {
            $exception->errorBag = 'walletSettings';
            throw $exception;
        }
        $settings->set('wallet.transfers', $request->boolean('transfers'));
        Audit::record('wallet.configured', [
            'currency' => $data['currency'],
            'transfers' => $request->boolean('transfers'),
        ]);

        return back()->with('status', __('site.account.wallet.settings_saved'));
    }

    public function export(): StreamedResponse
    {
        Audit::record('wallet.exported');

        return response()->streamDownload(
            function () {
                $stream = fopen('php://output', 'wb');
                fwrite($stream, "\xEF\xBB\xBF");
                fputcsv(
                    $stream,
                    [
                        'transaction',
                        'user',
                        'date (UTC)',
                        'amount_minor',
                        'currency',
                        'balance_before',
                        'balance_after',
                        'reason',
                    ],
                    ',',
                    '"',
                    '',
                );
                foreach (
                    WalletEntry::query()
                        ->join('wallet_transactions', 'wallet_transactions.id', '=', 'wallet_entries.transaction_id')
                        ->orderBy('wallet_entries.id')
                        ->select([
                            'transaction_id',
                            'user_id',
                            'wallet_entries.created_at',
                            'wallet_entries.amount_minor',
                            'currency',
                            'balance_before',
                            'balance_after',
                            'reason',
                        ])
                        ->cursor() as $entry
                ) {
                    /** @var WalletEntry $entry */
                    $reason = $entry->getAttribute('reason');
                    $reason = preg_match('/^[=+@\-\t\r]/', $reason) ? "'".$reason : $reason;
                    fputcsv(
                        $stream,
                        [
                            $entry->transaction_id,
                            $entry->user_id,
                            $entry->created_at,
                            $entry->amount_minor,
                            $entry->getAttribute('currency'),
                            $entry->balance_before,
                            $entry->balance_after,
                            $reason,
                        ],
                        ',',
                        '"',
                        '',
                    );
                }
                fclose($stream);
            },
            'wallet-'.now()->format('Ymd-His').'.csv',
            ['Content-Type' => 'text/csv; charset=UTF-8'],
        );
    }
}
