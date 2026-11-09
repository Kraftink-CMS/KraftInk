<?php

namespace App\Kraft\System;

use App\Models\Notifications\CommunityNotice;
use App\Events\AccountDeleting;
use App\Events\ExportingPersonalData;
use App\Kraft\Audit;
use App\Kraft\Auth\AccountIdentity;
use App\Kraft\Auth\Avatars;
use App\Kraft\Wallet\Money;
use App\Kraft\Wallet\Wallet;
use App\Models\Accounts\Role;
use App\Models\Accounts\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class PersonalData
{
    public function export(User $user): array
    {
        $avatar = null;
        if ($user->avatar_path) {
            $avatars = app(Avatars::class);
            $retained = $avatars->hasStoredImage($user);
            $avatar = ['url' => $retained ? $avatars->storedUrl($user) : null, 'retained' => $retained];
        }
        $data = [
            'exported_at' => now()->toIso8601String(),
            'profile' => $user->only([
                'id',
                'username',
                'email',
                'locale',
                'appearance',
                'created_at',
                'email_verified_at',
            ]) + ['balance' => Money::decimal(app(Wallet::class)->balance($user))],
            'avatar' => $avatar,
            'roles' => $user
                ->roles()
                ->get(['roles.id', 'name', 'slug'])
                ->toArray(),
            'game_identities' => $user
                ->gameIdentities()
                ->get(['game', 'uuid', 'username', 'verified_at', 'created_at'])
                ->toArray(),
            'connected_accounts' => $user
                ->oauthAccounts()
                ->get(['provider', 'subject', 'email', 'created_at'])
                ->toArray(),
            'passkeys' => $user->passkeys()->get(['name', 'rp_id', 'last_used_at', 'created_at'])->toArray(),
            'applications' => $user
                ->apiAccessTokens()
                ->orderBy('created_at')
                ->select(['name', 'scopes', 'expires_at', 'last_used_at', 'revoked_at', 'created_at'])
                ->cursor(),
            'media' => $user
                ->mediaAssets()
                ->orderBy('id')
                ->select(['id', 'original_name', 'mime', 'size', 'alt', 'created_at'])
                ->cursor(),
            'content' => $user
                ->contentEntries()
                ->withTrashed()
                ->orderBy('id')
                ->select(['kind', 'locale', 'title', 'slug', 'body', 'published_at', 'created_at'])
                ->cursor(),
            'notifications' => CommunityNotice::query()
                ->where(
                    fn ($query) => $query
                        ->where('author_id', $user->id)
                        ->orWhere(fn ($query) => $query->where('audience', 'user')->where('audience_id', $user->id)),
                )
                ->orderBy('id')
                ->select(['id', 'title', 'body', 'url', 'published_at', 'expires_at'])
                ->cursor(),
            'notification_reads' => $user
                ->communityNoticeReads()
                ->orderBy('id')
                ->select(['notice_id', 'read_at'])
                ->cursor(),
            'security_activity' => $user
                ->auditEntries()
                ->orderBy('id')
                ->select(['action', 'ip', 'created_at'])
                ->cursor(),
        ];
        $event = new ExportingPersonalData($user, $data);
        event($event);
        $snapshot = app(DataExportSnapshot::class);

        return $snapshot->freeze($event->data);
    }

    public function anonymize(User $user): void
    {
        DB::transaction(function () use ($user) {
            $user = User::query()->lockForUpdate()->findOrFail($user->id);
            if ($user->anonymized_at) {
                return;
            }
            $owner = Role::query()->where('slug', 'owner')->lockForUpdate()->first();
            if ($owner && !$user->isServiceAccount() && $user->roles()->where('slug', 'owner')->exists()) {
                $another = User::members()
                    ->whereKeyNot($user->id)
                    ->whereNull('anonymized_at')
                    ->where('import_locked', false)
                    ->withoutActiveBan()
                    ->whereHas('roles', fn ($query) => $query->where('roles.id', $owner->id))
                    ->exists();
                if (!$another) {
                    throw ValidationException::withMessages(['account' => __('site.account.privacy.last_owner')]);
                }
            }
            $replacement = AccountIdentity::availableUsername('Deleted-'.Str::lower(Str::random(12)));
            $event = new AccountDeleting($user, $replacement);
            event($event);
            if ($event->blockedReason) {
                throw ValidationException::withMessages(['account' => $event->blockedReason]);
            }
            DB::table('password_reset_tokens')->where('email', $user->email)->delete();
            $user->oauthAccounts()->delete();
            $user->passkeys()->delete();
            $user->apiAccessTokens()->delete();
            $user->gameIdentities()->delete();
            $user->importCredential()->delete();
            DB::table('sessions')->where('user_id', $user->id)->delete();
            $user->communityNoticeReads()->delete();
            $user->auditEntries()->update(['ip' => null]);
            $user->mediaAssets()->update(['owner_id' => null]);
            $user->roles()->detach();
            $user
                ->forceFill([
                    'username' => $replacement,
                    'appearance' => null,
                    'email' => Str::uuid().'@deleted.invalid',
                    'password' => Str::random(80),
                    'email_verified_at' => null,
                    'remember_token' => Str::random(80),
                    'two_factor_secret' => null,
                    'two_factor_recovery_codes' => null,
                    'two_factor_confirmed_at' => null,
                    'two_factor_last_step' => null,
                    'local_login_enabled' => false,
                    'import_locked' => true,
                    'anonymized_at' => now(),
                    'auth_session_version' => $user->auth_session_version + 1,
                ])
                ->save();
            Audit::record('account.anonymized', ['id' => $user->id]);
            $user->auditEntries()->update(['ip' => null]);
        }, 3);
    }
}
