<?php

namespace App\Kraft\Extensions;

use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;

class ExtensionListing
{
    public function filters(Request $request): array
    {
        $search = $request->query('q');
        $search = is_string($search) ? mb_substr(trim($search), 0, 120) : '';
        $state = $request->query('state');
        $state = in_array($state, ExtensionState::listingValues(), true) ? $state : '';
        $origin = $request->query('origin');
        $origin = in_array($origin, ['builtin', 'external'], true) ? $origin : '';

        return ['q' => $search, 'state' => $state, 'origin' => $origin];
    }

    public function filter(array $extensions, Request $request): array
    {
        ['q' => $search, 'state' => $state, 'origin' => $origin] = $this->filters($request);
        $extensions = array_filter($extensions, function (array $extension) use ($search, $state, $origin): bool {
            return ($search === '' || mb_stripos($extension['name'].' '.$extension['id'], $search) !== false) &&
                ($state === '' || $extension['installation_state'] === $state) &&
                ($origin === '' || (bool) ($extension['builtin'] ?? false) === ($origin === 'builtin'));
        });
        uasort($extensions, fn (array $left, array $right) => strnatcasecmp($left['name'], $right['name']) ?: strcmp($left['id'], $right['id']));

        return $extensions;
    }

    public function paginate(array $extensions, Request $request): LengthAwarePaginator
    {
        $extensions = $this->filter($extensions, $request);
        $page = min(max(1, (int) ceil(count($extensions) / 15)), max(1, $request->integer('extension_page', 1)));
        $query = array_filter($this->filters($request), fn ($value) => $value !== '');
        foreach (['retired_page', 'catalogue_page'] as $pageName) {
            if ($request->has($pageName)) {
                $query[$pageName] = max(1, $request->integer($pageName, 1));
            }
        }
        if ($request->boolean('catalogue')) {
            $query['catalogue'] = 1;
        }

        return new LengthAwarePaginator(
            array_slice($extensions, ($page - 1) * 15, 15, true),
            count($extensions),
            15,
            $page,
            [
                'path' => $request->url(),
                'pageName' => 'extension_page',
                'fragment' => 'extensions',
                'query' => $query,
            ],
        );
    }

    public function presentation(array $extension, array $selectedThemes = [], ?string $activeGame = null, bool $safeMode = false): array
    {
        $lifecycle = ExtensionState::from($extension['installation_state']);
        $state = $lifecycle->value;
        $builtin = (bool) ($extension['builtin'] ?? false);
        $valid = (bool) $extension['valid'];
        $installed = (bool) $extension['installed'];
        $compatible = $extension['game_compatible'] ?? true;
        $ready = $valid && $installed && $extension['manifest_current'] && $state !== 'quarantined';
        $selected = $extension['type'] === 'game'
            ? $activeGame === $extension['id']
            : in_array($extension['id'], $selectedThemes, true);
        $removable = $valid && !$builtin && !$selected &&
            (!$installed || $ready) &&
            ($extension['type'] === 'game' || (!$extension['enabled'] && !($extension['requested_enabled'] ?? false)));

        return [
            'state' => $state,
            'tone' => match ($lifecycle) {
                ExtensionState::Active => 'success',
                ExtensionState::Suspended => 'warning',
                default => 'secondary',
            },
            'label' => $extension['type'] === 'game' && $lifecycle === ExtensionState::Active
                ? 'site.games.module_available' : 'system.extensions.states.'.$state,
            'builtin' => $builtin,
            'selected' => $selected,
            'canInstall' => !$safeMode && $compatible && $valid && !$builtin && !$installed,
            'canRepair' => !$safeMode && $compatible && $valid && !$builtin && $installed && $state === 'quarantined',
            'canEnable' => !$safeMode && $compatible && $ready && $extension['type'] === 'plugin' && !$extension['enabled'],
            'canDisable' => !$safeMode && $valid && $installed && $extension['type'] === 'plugin' &&
                ($extension['enabled'] || ($extension['requested_enabled'] ?? false)),
            'canSelect' => !$safeMode && $ready && $extension['type'] === 'theme' && !$selected,
            'canConfigure' => !$safeMode && $compatible && $ready,
            'canRemove' => !$safeMode && $removable,
            'removalAction' => $installed ? 'uninstall' : 'retire',
        ];
    }
}
