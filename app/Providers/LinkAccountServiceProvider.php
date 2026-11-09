<?php

namespace App\Providers;

use App\Models\Audit\AuditEntry;
use App\Events\AccountDeleting;
use App\Events\ExportingPersonalData;
use App\Models\Servers\LinkAccountToken;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;

class LinkAccountServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        Event::listen(ExportingPersonalData::class, function (ExportingPersonalData $event) {
            $event->data['link_account_access'] = [
                'grants' => $event->user
                    ->linkAccountTokens()
                    ->orderBy('id')
                    ->select(['name', 'server_id', 'scopes', 'expires_at', 'revoked_at', 'created_at'])
                    ->cursor(),
                'wallet_operations' => $event->user
                    ->linkAccountOperations()
                    ->orderBy('id')
                    ->select(['id', 'kind', 'result', 'created_at'])
                    ->cursor(),
                'registration_consents' => AuditEntry::query()
                    ->where('action', 'link.account_registered')
                    ->where('context->id', $event->user->id)
                    ->orderBy('id')
                    ->select(['context', 'created_at'])
                    ->cursor(),
            ];
        });
        Event::listen(AccountDeleting::class, function (AccountDeleting $event) {
            LinkAccountToken::query()
                ->where('actor_id', $event->user->id)
                ->update([
                    'actor_id' => null,
                    'name' => '',
                    'revoked_at' => now(),
                ]);
        });
    }
}
