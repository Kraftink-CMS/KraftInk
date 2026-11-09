<?php

namespace App\Http\Controllers\Admin\Settings;

use App\Http\Controllers\Controller;
use App\Kraft\Audit;
use App\Kraft\Auth\AvatarPolicy;
use App\Kraft\Auth\Avatars\AvatarProviders;
use App\Kraft\Games\GameRegistry;
use App\Kraft\Settings;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class AvatarSettingsController extends Controller
{
    public function index(Request $request, GameRegistry $games, AvatarProviders $providers, AvatarPolicy $policy): View
    {
        $game = $games->activeId();
        $initialUploads = $policy->allowsUploads();
        $initialProvider = $game ? $policy->providerId($game) : null;
        $adapter = $games->find($game);
        $gameName = $adapter
            ? __($adapter->metadata()['name'])
            : ($game
                ? __('admin.settings.avatars.unavailable_game', ['game' => $game])
                : null);
        $options = [];
        foreach ($game ? $providers->forGame($game) : [] as $id => $provider) {
            $metadata = $provider->metadata();
            $website = $metadata['website'] ?? null;
            $options[$id] = [
                'name' => __($metadata['name']),
                'description' => empty($metadata['description']) ? '' : __($metadata['description']),
                'website' => is_string($website) &&
                    filter_var($website, FILTER_VALIDATE_URL) &&
                    in_array(parse_url($website, PHP_URL_SCHEME), ['http', 'https'], true)
                        ? $website
                        : null,
            ];
        }
        $oldGame = $request->old('game', $game);
        $sameGame = ($oldGame === '' ? null : $oldGame) === $game;
        $selectedProvider = $sameGame ? $request->old('provider', $initialProvider) : $initialProvider;
        if (!is_string($selectedProvider) || $selectedProvider === '') {
            $selectedProvider = null;
        } elseif (!isset($options[$selectedProvider]) && $selectedProvider !== $initialProvider) {
            $selectedProvider = $initialProvider;
        }
        $allowUploads = in_array($request->old('allow_uploads', $initialUploads), [true, 1, '1'], true);
        $noneDescription = !$game
            ? __('admin.settings.avatars.no_game')
            : (!$options
                ? __('admin.settings.avatars.no_providers')
                : __('admin.settings.avatars.none_description'));
        $description =
            $selectedProvider === null
                ? $noneDescription
                : $options[$selectedProvider]['description'] ?? __('admin.settings.avatars.unavailable_description');

        return view('admin.settings.avatars.index', [
            'game' => $game,
            'gameName' => $gameName,
            'providerOptions' => $options,
            'selectedProvider' => $selectedProvider,
            'providerUnavailable' => $selectedProvider !== null && !isset($options[$selectedProvider]),
            'selectedDescription' => $description,
            'noneDescription' => $noneDescription,
            'allowUploads' => $allowUploads,
            'showUploadPriority' => $allowUploads && $selectedProvider !== null && isset($options[$selectedProvider]),
        ]);
    }

    public function store(
        Request $request,
        GameRegistry $games,
        AvatarProviders $providers,
        AvatarPolicy $policy,
        Settings $settings,
    ): RedirectResponse {
        $data = $request->validate([
            'allow_uploads' => 'required|boolean',
            'game' => 'present|nullable|string|max:100',
            'provider' => 'present|nullable|string|max:160',
        ]);
        $game = $games->activeId();
        if (($data['game'] ?? null) !== $game) {
            throw ValidationException::withMessages(['game' => __('admin.settings.avatars.game_changed')]);
        }
        $provider = $data['provider'] ?? null;
        $available = $game ? $providers->forGame($game) : [];
        $previous = $game ? $policy->providerId($game) : null;
        if ($provider !== null && !isset($available[$provider]) && $provider !== $previous) {
            throw ValidationException::withMessages(['provider' => __('admin.settings.avatars.invalid_provider')]);
        }
        $allowUploads = $request->boolean('allow_uploads');
        DB::transaction(function () use ($settings, $game, $provider, $allowUploads) {
            $settings->set('avatars.allow_uploads', $allowUploads);
            if ($game !== null) {
                $settings->set('avatars.providers.'.$game, $provider);
            }
            Audit::record('avatars.settings_updated', [
                'game' => $game,
                'provider' => $provider,
                'allow_uploads' => $allowUploads,
            ]);
        });

        return back()->with('status', __('common.labels.settings_have_been_saved'));
    }
}
