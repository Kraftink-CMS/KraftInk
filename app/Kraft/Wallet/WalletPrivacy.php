<?php

namespace App\Kraft\Wallet;

use App\Events\AccountDeleting;
use App\Events\ExportingPersonalData;
use App\Models\Wallet\WalletEntry;
use App\Models\Wallet\WalletTransaction;

class WalletPrivacy
{
    public function export(ExportingPersonalData $event): void
    {
        $event->data['wallet'] = [
            'currency' => app(Wallet::class)->currency(),
            'balance_minor' => app(Wallet::class)->balance($event->user),
            'entries' => WalletEntry::query()
                ->where('user_id', $event->user->id)
                ->with('transaction')
                ->orderBy('id')
                ->lazyById(100)
                ->map(
                    fn (WalletEntry $entry) => [
                        'id' => $entry->id,
                        'transaction' => $entry->transaction_id,
                        'kind' => $entry->transaction->kind,
                        'reason' => $entry->transaction->reason,
                        'amount_minor' => $entry->amount_minor,
                        'balance_before' => $entry->balance_before,
                        'balance_after' => $entry->balance_after,
                        'currency' => $entry->transaction->currency,
                        'created_at' => $entry->created_at->toIso8601String(),
                    ],
                ),
        ];
    }

    public function anonymize(AccountDeleting $event): void
    {
        WalletTransaction::query()
            ->where('actor_id', $event->user->id)
            ->where('kind', 'transfer')
            ->orderBy('id')
            ->each(function (WalletTransaction $transaction) {
                $context = $transaction->context ?? [];
                unset($context['note']);
                $transaction->update(['context' => $context]);
            });
    }
}
