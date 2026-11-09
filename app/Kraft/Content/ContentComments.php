<?php

namespace App\Kraft\Content;

use App\Models\Content\ContentComment;
use App\Models\Content\ContentEntry;
use App\Models\Accounts\User;
use Illuminate\Validation\ValidationException;
use HTMLPurifier_Config;
use HTMLPurifier;

class ContentComments
{
    public function locked(int $id, bool $trashed = false, bool $withTrashedEntry = false): ContentComment
    {
        $query = $trashed ? ContentComment::onlyTrashed() : ContentComment::query();
        $candidate = (clone $query)->findOrFail($id);
        $entries = $withTrashedEntry ? ContentEntry::withTrashed() : ContentEntry::query();
        $entries->lockForUpdate()->findOrFail($candidate->content_entry_id);

        return $query->where('content_entry_id', $candidate->content_entry_id)->lockForUpdate()->findOrFail($id);
    }

    public function canEdit(ContentComment $comment, ?User $user): bool
    {
        return $user?->hasVerifiedEmail() &&
            ($user->hasPermission('content.comments.manage') ||
                ($comment->user_id === $user->id && $comment->state !== 'hidden'));
    }

    public function canDelete(ContentComment $comment, ?User $user): bool
    {
        return $user && ($user->hasPermission('content.comments.manage') || $comment->user_id === $user->id);
    }

    public function clean(string $body): string
    {
        $config = HTMLPurifier_Config::createDefault();
        $config->set('Cache.SerializerPath', storage_path('framework/cache'));
        $config->set('HTML.Allowed', 'p,br,strong,b,em,i,u,s,ul,ol,li,blockquote,pre,code,a[href|title]');
        $config->set('URI.AllowedSchemes', ['https' => true, 'http' => true]);
        $clean = new HTMLPurifier($config)->purify($body);
        $text = html_entity_decode(strip_tags($clean), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        if (preg_replace('/[\s\p{Z}\x{200B}\x{FEFF}]+/u', '', $text) === '') {
            throw ValidationException::withMessages(['body' => __('site.content.empty_comment')]);
        }

        return $clean;
    }
}
