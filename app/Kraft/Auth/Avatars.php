<?php

namespace App\Kraft\Auth;

use App\Events\AccountDeleting;
use App\Kraft\Audit;
use App\Kraft\Media\FileDeletionQueue;
use App\Kraft\Media\Images;
use App\Models\Accounts\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use RuntimeException;
use Throwable;

class Avatars
{
    public function controls(User $user): AvatarControls
    {
        return new AvatarControls(
            url: $this->url($user),
            canUpload: app(AvatarPolicy::class)->allowsUploads(),
            canDelete: (bool) $user->avatar_path,
        );
    }

    public function prepare(iterable $users): void
    {
        app(AvatarResolver::class)->prepare($users);
    }

    public function url(User $user): string
    {
        if ($user->anonymized_at) {
            return asset('images/avatar.svg');
        }
        if ($local = $this->storedUrl($user)) {
            return $local;
        }

        return app(AvatarResolver::class)->forUser($user) ?? asset('images/avatar.svg');
    }

    public function storedUrl(User $user): ?string
    {
        return !$user->anonymized_at && $user->avatar_path && app(AvatarPolicy::class)->allowsUploads()
            ? route('site.avatars.show', [$user->id, $this->version($user)])
            : null;
    }

    public function hasStoredImage(User $user): bool
    {
        return $this->validPath($user->avatar_path) && Storage::disk('local')->exists($user->avatar_path);
    }

    public function version(User $user): string
    {
        return substr(hash('sha256', $user->avatar_path ?? ''), 0, 40);
    }

    public function store(User $user, UploadedFile $file): void
    {
        if (!app(AvatarPolicy::class)->allowsUploads()) {
            throw ValidationException::withMessages(['avatar' => __('admin.settings.avatars.uploads_disabled')]);
        }
        $images = app(Images::class);
        $image = $images->inspect($file);
        if (
            !$image ||
            !in_array($image->mime, ['image/jpeg', 'image/png', 'image/webp'], true) ||
            $image->size > 2097152 ||
            min($image->width, $image->height) < 32 ||
            max($image->width, $image->height) > 1024
        ) {
            throw ValidationException::withMessages(['avatar' => __('site.account.settings.avatar_invalid')]);
        }
        $image = $images->prepareUpload($file, $image, field: 'avatar');
        $path = 'avatars/'.Str::uuid().'.'.$image->extension;
        try {
            if (!Storage::disk('local')->putFileAs('avatars', $image->path, basename($path))) {
                throw new RuntimeException('Cannot store avatar.');
            }
            $this->replace($user, $path);
        } catch (Throwable $exception) {
            $this->scheduleDeletion($path);
            throw $exception;
        }
        if (DB::transactionLevel() > 0) {
            DB::afterRollBack(fn () => $this->scheduleDeletion($path));
        }
        Audit::record('account.avatar_updated', ['id' => $user->id]);
    }

    public function remove(User $user): void
    {
        $this->replace($user, null);
        Audit::record('account.avatar_removed', ['id' => $user->id]);
    }

    public function anonymize(AccountDeleting $event): void
    {
        $this->replace($event->user, null);
    }

    private function replace(User $user, ?string $path): void
    {
        DB::transaction(function () use ($user, $path): void {
            $locked = User::query()->lockForUpdate()->findOrFail($user->id);
            $old = $locked->avatar_path;
            $locked->forceFill(['avatar_path' => $path])->save();
            $this->scheduleDeletion($old);
        }, 3);
        $user->forceFill(['avatar_path' => $path]);
    }

    private function validPath(?string $path): bool
    {
        return $path !== null && preg_match('~^avatars/[a-f0-9-]{36}\.(?:png|jpg|webp)$~D', $path) === 1;
    }

    private function scheduleDeletion(?string $path): void
    {
        if ($this->validPath($path)) {
            app(FileDeletionQueue::class)->schedule($path, 'avatars', ['users' => 'avatar_path']);
        }
    }
}
