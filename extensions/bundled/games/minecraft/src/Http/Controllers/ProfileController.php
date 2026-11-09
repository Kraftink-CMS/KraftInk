<?php

namespace KraftInk\Games\Minecraft\Http\Controllers;

use App\Kraft\Auth\AuthForms;
use App\Kraft\Auth\LoginManager;
use App\Kraft\Games\GameRegistry;
use App\Kraft\Settings;
use App\Models\Accounts\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use KraftInk\Games\Minecraft\Services\Profiles\FloodgateProfiles;
use KraftInk\Games\Minecraft\Adapters\MinecraftAdapter;
use Symfony\Component\HttpKernel\Exception\HttpException;

class ProfileController
{
    public function show(Request $request): Response
    {
        $this->pending($request);

        return AuthForms::render(
            $request,
            'minecraft::default.profile.edit',
            'minecraft::default.profile.partials.edit',
            __('minecraft::messages.profile_title'),
            ['floodgateEnabled' => app(FloodgateProfiles::class)->enabled()],
        );
    }

    public function store(Request $request, MinecraftAdapter $profiles, GameRegistry $games): RedirectResponse
    {
        [$pending, $user] = $this->pending($request);
        $data = $request->validate([
            'username' => ['required', 'string', 'max:17'],
        ]);
        $profile = $profiles->profileByUsername($data['username']);
        $existing = $games->identityFor($user, 'minecraft');
        if (!$existing?->verified_at) {
            $games->declareIdentity($user, 'minecraft', $profile, [
                'source' => app(FloodgateProfiles::class)->isBedrockName($profile['username'])
                    ? 'geyser_public_profile'
                    : 'mojang_profile',
                'profile_checked_at' => now()->toIso8601String(),
            ]);
        }

        return app(LoginManager::class)->complete($request, $user, $pending['remember'], false, 'minecraft_profile');
    }

    private function pending(Request $request): array
    {
        $pending = $request->session()->get('auth.identity_pending');
        if (
            app(Settings::class)->get('games.default') !== 'minecraft' ||
            app(Settings::class)->get('games.minecraft.authentication') !== 'profile' ||
            !$pending ||
            $pending['at'] <= time() - LoginManager::IDENTITY_PENDING_SECONDS
        ) {
            throw new HttpException(403);
        }

        $user = User::query()->findOrFail($pending['id']);
        if (
            ($pending['version'] ?? null) !== (int) $user->auth_session_version ||
            !$user->canAuthenticateAsMember()
        ) {
            $request->session()->forget('auth.identity_pending');
            throw new HttpException(403);
        }

        return [$pending, $user];
    }
}
