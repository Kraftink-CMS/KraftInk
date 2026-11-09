<?php

namespace App\Providers;

use App\Events\AccountDeleting;
use App\Events\ConfigurationChanged;
use App\Events\ExportingPersonalData;
use App\Events\OAuthAccountUnlinked;
use App\Kraft\OAuth\Discord\RoleConnections;
use App\Kraft\OAuth\Discord\RoleMetadata;
use App\Models\Accounts\Role;
use App\Models\Accounts\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\ServiceProvider;
use Throwable;

class DiscordRoleServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->scoped(RoleMetadata::class);
    }

    public function boot(): void
    {
        $later = function (callable $action) {
            DB::afterCommit(function () use ($action) {
                try {
                    $action();
                } catch (Throwable $exception) {
                    Log::warning('Discord role reconciliation postponed', ['exception' => $exception::class]);
                }
            });
        };
        User::saved(fn (User $user) => $later(fn () => app(RoleConnections::class)->reconcileUser($user->id)));
        User::deleting(function (User $user) {
            app(RoleConnections::class)->revokeUser($user->id, 'account_deleted');
        });
        Role::saved(fn () => $later(fn () => app(RoleConnections::class)->due()));
        Role::deleted(fn () => $later(fn () => app(RoleConnections::class)->due()));
        Event::listen(ConfigurationChanged::class, function (ConfigurationChanged $event) use ($later) {
            if (str_starts_with($event->key, 'oauth.') || str_starts_with($event->key, 'discord.roles.')) {
                $later(fn () => app(RoleConnections::class)->reconcileConfiguration());
            }
        });
        Event::listen(OAuthAccountUnlinked::class, function (OAuthAccountUnlinked $event) {
            $event->user
                ->discordRoleConnections()
                ->where('provider', $event->provider)
                ->each(fn ($connection) => app(RoleConnections::class)->revoke($connection, 'login_unlinked'));
        });
        Event::listen(AccountDeleting::class, function (AccountDeleting $event) {
            app(RoleConnections::class)->revokeUser($event->user->id, 'account_closed');
            $event->user->discordRoleRevocations()->update(['user_id' => null]);
        });
        Event::listen(ExportingPersonalData::class, function (ExportingPersonalData $event) {
            $event->data['discord_linked_roles'] = [
                'connections' => $event->user
                    ->discordRoleConnections()
                    ->get([
                        'provider',
                        'application_id',
                        'subject',
                        'fields',
                        'consented_at',
                        'last_synced_at',
                        'last_error',
                    ])
                    ->makeVisible('subject')
                    ->toArray(),
                'removals' => $event->user
                    ->discordRoleRevocations()
                    ->get(['provider', 'application_id', 'reason', 'state', 'created_at', 'completed_at'])
                    ->toArray(),
            ];
        });
    }
}
