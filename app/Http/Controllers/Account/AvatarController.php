<?php

namespace App\Http\Controllers\Account;

use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\Exception\HttpException;
use App\Http\Controllers\Controller;
use App\Kraft\Auth\Avatars;
use App\Kraft\Auth\AvatarPolicy;
use App\Models\Accounts\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class AvatarController extends Controller
{
    public function show(User $user, string $version, Avatars $avatars): BinaryFileResponse
    {
        if (
            !(
                !$user->anonymized_at &&
                app(AvatarPolicy::class)->allowsUploads() &&
                $user->avatar_path &&
                hash_equals($avatars->version($user), $version) &&
                $avatars->hasStoredImage($user)
            )
        ) {
            throw new NotFoundHttpException();
        }

        return response()
            ->file(Storage::disk('local')->path($user->avatar_path), [
                'Content-Type' => ['png' => 'image/png', 'jpg' => 'image/jpeg', 'webp' => 'image/webp'][
                    pathinfo($user->avatar_path, PATHINFO_EXTENSION)
                ],
                'X-Content-Type-Options' => 'nosniff',
                'Cache-Control' => 'private, no-cache, must-revalidate',
                'Content-Security-Policy' => "default-src 'none'; sandbox",
            ])
            ->setPrivate();
    }

    public function store(Request $request, AvatarPolicy $policy, Avatars $avatars): RedirectResponse
    {
        if (!$policy->allowsUploads()) {
            throw new HttpException(403);
        }
        $request->validate([
            'avatar' => 'required|file|max:2048|mimetypes:image/png,image/jpeg,image/webp',
        ]);

        $avatars->store($request->user(), $request->file('avatar'));

        return back()->with('status', __('site.account.settings.avatar_saved'));
    }

    public function destroy(Request $request, Avatars $avatars): RedirectResponse
    {
        $avatars->remove($request->user());

        return back()->with('status', __('site.account.settings.avatar_removed'));
    }
}
