<?php

namespace App\Kraft\Auth;

use App\Models\Accounts\UserBan;
use App\Events\AccountDeleting;
use App\Events\ExportingPersonalData;

class AdministrationPrivacy
{
    public function export(ExportingPersonalData $event): void
    {
        $event->data['account_activity'] = $event->user->only([
            'last_login_at',
            'last_login_ip',
            'oauth_recovery_required_at',
        ]);
        $event->data['account_restrictions'] = $event->user
            ->bans()
            ->orderBy('id')
            ->select(['reason', 'expires_at', 'revoked_at', 'created_at'])
            ->cursor();
    }

    public function anonymize(AccountDeleting $event): void
    {
        $event->user->bans()->delete();
        UserBan::query()
            ->where('author_id', $event->user->id)
            ->update(['author_id' => null]);
        UserBan::query()
            ->where('revoked_by', $event->user->id)
            ->update(['revoked_by' => null]);
        $event->user
            ->forceFill(['last_login_at' => null, 'last_login_ip' => null, 'oauth_recovery_required_at' => null])
            ->save();
    }
}
