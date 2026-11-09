<?php

namespace App\Kraft\Games;

use App\Kraft\Audit;
use App\Kraft\Auth\InGameSignIn\InGameSignInPolicy;
use App\Kraft\Settings;
use App\Kraft\System\SensitiveAction;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use RuntimeException;

class GameSettings
{
    public function __construct(
        private readonly GameRegistry $games,
        private readonly Settings $settings,
        private readonly InGameSignInPolicy $inGameSignIn,
    ) {
    }

    public function forDisplay(array $games, Request $request, bool $siteAccess = false): array
    {
        foreach ($games as $id => &$game) {
            $game['has_site_access'] = $this->fields($game['settings'], true) !== [];
            $game['settings'] = $this->fields($game['settings'], $siteAccess);
            $game['setting_values'] = [];
            foreach ($game['settings'] as $key => &$field) {
                $field['options'] = $this->options($field);
                $setting = $this->settings->get('games.'.$id.'.'.$key, $field['default']);
                $game['setting_values'][$key] = $request->old('game_settings.'.$id.'.'.$key, $setting);
            }
            unset($field);
        }
        unset($game);

        return $siteAccess ? array_filter($games, fn (array $game): bool => $game['has_site_access']) : $games;
    }

    public function save(Request $request, ?Closure $saveSettings = null, ?Closure $confirmSettings = null): void
    {
        $siteAccess = $saveSettings !== null;
        $updateGame = !$siteAccess || $request->hasAny(['auth_game', 'game_settings']);
        $this->inGameSignIn->withStableConfiguration(function () use ($request, $siteAccess, $updateGame, $saveSettings, $confirmSettings): void {
            [$game, $submitted, $confirmGame] = $updateGame ? $this->changes($request, $siteAccess) : [null, [], false];
            $requiresConfirmation = $confirmGame || $confirmSettings?->__invoke();
            DB::transaction(function () use ($request, $siteAccess, $game, $submitted, $requiresConfirmation, $saveSettings): void {
                if ($requiresConfirmation) {
                    app(SensitiveAction::class)->confirm($request);
                }
                if (!$siteAccess && $game !== $this->settings->get('games.default')) {
                    if ($game !== $this->games->activeId()) {
                        $this->clearGameCaches();
                    }
                    $this->settings->set('games.default', $game);
                }
                foreach ($submitted as $key => $value) {
                    $this->settings->set('games.'.$game.'.'.$key, $value);
                }
                $saveSettings?->__invoke();
            });
        });
        if ($updateGame) {
            Audit::record('games.settings_saved');
        }
    }

    private function clearGameCaches(): void
    {
        if (app()->routesAreCached()) {
            Artisan::call('route:clear');
        }
        if (app()->configurationIsCached()) {
            Artisan::call('config:clear');
        }
        if (app()->routesAreCached() || app()->configurationIsCached()) {
            throw new RuntimeException('Could not clear the application caches before switching games.');
        }
    }

    private function changes(Request $request, bool $siteAccess): array
    {
        $games = $this->games->all();
        $active = $this->games->activeId();
        $gameInput = $siteAccess ? 'auth_game' : 'default_game';
        $selected = $request->input($gameInput);
        $available = $siteAccess
            ? ($active !== null && $this->fields($games[$active]['settings'] ?? [], true) !== [] ? [$active] : [])
            : array_keys($games);
        $rules = [$gameInput => ['required', 'string', Rule::in($available)], 'game_settings' => 'nullable|array'];
        $schema = is_string($selected) ? $games[$selected]['settings'] ?? [] : [];
        $fields = $this->fields($schema, $siteAccess);
        if (is_string($selected) && isset($games[$selected])) {
            $path = 'game_settings.'.$selected;
            $rules['game_settings'] = ['nullable', 'array:'.$selected];
            $rules[$path] = $fields ? ['sometimes', 'array:'.implode(',', array_keys($fields))] : ['sometimes', 'array', 'max:0'];
            foreach ($fields as $key => $field) {
                $rules[$path.'.'.$key] = [
                    ($field['required'] ?? true) ? 'required' : 'sometimes',
                    Rule::in(array_keys($this->options($field))),
                ];
            }
        }
        $data = $request->validate($rules);
        $game = $data[$gameInput];
        $submitted = $data['game_settings'][$game] ?? [];
        $current = [];
        foreach ($schema as $key => $field) {
            $current[$key] = $this->settings->get('games.'.$game.'.'.$key, $field['default']);
        }
        $values = array_replace($current, $submitted);
        if ($game !== $active || array_diff_assoc($values, $current) !== []) {
            $this->games->get($game)->validateSettings($request->user(), $values, $this->settings);
            $this->inGameSignIn->ensureKeepsSignIn($game, $values);
        }
        foreach ($fields as $key => $field) {
            if (($field['sensitive'] ?? false) && (string) $values[$key] !== (string) $current[$key]) {
                return [$game, $submitted, true];
            }
        }

        return [$game, $submitted, false];
    }

    private function fields(array $schema, bool $siteAccess): array
    {
        return array_filter($schema, fn (array $field): bool => (($field['scope'] ?? null) === 'site_access') === $siteAccess);
    }

    private function options(array $field): array
    {
        return $field['options'] + (isset($field['options_from']) ? app()->call($field['options_from']) : []);
    }
}
