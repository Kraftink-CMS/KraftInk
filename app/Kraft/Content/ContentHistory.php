<?php

namespace App\Kraft\Content;

use App\Kraft\Html;
use App\Models\Content\ContentEntry;
use App\Models\Content\ContentRevision;
use App\Models\Accounts\Role;
use App\Models\Accounts\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use App\Kraft\Redirects;
use App\Models\Content\ContentImage;
use App\Models\Media\MediaAsset;

class ContentHistory
{
    public const array FIELDS = [
        'kind',
        'locale',
        'slug',
        'title',
        'body',
        'summary',
        'cover_media_id',
        'cover_image_id',
        'is_pinned',
        'comments_enabled',
        'reactions_enabled',
        'comments_moderated',
        'visibility',
        'roles_restricted',
        'required_permission',
        'published_at',
        'author_id',
    ];

    public function record(ContentEntry $entry, ?User $actor, string $action): ContentRevision
    {
        $snapshot = $entry->only(self::FIELDS);
        $snapshot['role_ids'] = $entry->roles()->pluck('roles.id')->all();
        $snapshot['attachment_ids'] = $entry->attachments()->pluck('id')->all();

        return $entry->revisions()->create(['actor_id' => $actor?->id, 'action' => $action, 'snapshot' => $snapshot]);
    }

    public function restore(ContentEntry $entry, ContentRevision $revision, User $actor): void
    {
        if ($revision->content_entry_id !== $entry->id) {
            abort(404);
        }
        DB::transaction(function () use ($entry, $revision, $actor) {
            $entry = ContentEntry::query()->lockForUpdate()->findOrFail($entry->id);
            $snapshot = $revision->snapshot;
            if (
                ContentEntry::withTrashed()
                    ->where('kind', $snapshot['kind'])
                    ->where('locale', $snapshot['locale'])
                    ->where('slug', $snapshot['slug'])
                    ->whereKeyNot($entry->id)
                    ->exists()
            ) {
                throw ValidationException::withMessages(['revision' => __('site.content.restore_slug_taken')]);
            }
            $roles = Role::query()
                ->whereIn('id', $snapshot['role_ids'] ?? [])
                ->pluck('id')
                ->all();
            if (
                ($snapshot['visibility'] ?? 'public') === 'restricted' &&
                !$roles &&
                (!empty($snapshot['roles_restricted']) ||
                    !empty($snapshot['role_ids']) ||
                    empty($snapshot['required_permission']))
            ) {
                throw ValidationException::withMessages(['revision' => __('site.content.restore_roles_missing')]);
            }
            $this->record($entry, $actor, 'before_restore');
            $oldPath = parse_url($entry->url(), PHP_URL_PATH);
            $attributes = array_intersect_key($snapshot, array_flip(self::FIELDS));
            $attributes['roles_restricted'] = (bool) ($snapshot['roles_restricted'] ?? !empty($snapshot['role_ids']));
            $attributes['body'] = Html::clean($attributes['body'] ?? '');
            $attributes['cover_image_id'] = $snapshot['cover_image_id'] ?? null;
            if (
                $attributes['cover_image_id'] &&
                !ContentImage::query()->whereKey($attributes['cover_image_id'])->exists()
            ) {
                $attributes['cover_image_id'] = null;
            }
            if ($attributes['cover_image_id']) {
                $attributes['cover_media_id'] = null;
            }
            if (
                !empty($attributes['cover_media_id']) &&
                !MediaAsset::query()->whereKey($attributes['cover_media_id'])->exists()
            ) {
                $attributes['cover_media_id'] = null;
            }
            $entry->fill($attributes)->save();
            if ($oldPath !== parse_url($entry->url(), PHP_URL_PATH)) {
                app(Redirects::class)->preserve($oldPath, parse_url($entry->url(), PHP_URL_PATH));
            }
            $entry->roles()->sync($roles);
            app(ContentImages::class)->attach($entry, $actor);
            $entry
                ->attachments()
                ->whereNotIn('id', $snapshot['attachment_ids'] ?? [])
                ->delete();
            $entry
                ->attachments()
                ->withTrashed()
                ->whereIn('id', $snapshot['attachment_ids'] ?? [])
                ->restore();
            $this->record($entry, $actor, 'restored');
        });
    }
}
