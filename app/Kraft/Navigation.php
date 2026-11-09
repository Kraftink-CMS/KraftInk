<?php

namespace App\Kraft;

use App\Kraft\Auth\PermissionAccess;
use App\Kraft\Extensions\ExtensionManager;
use App\Kraft\Site\ViewCapabilities;
use App\Models\Site\NavigationLink;
use App\Models\Accounts\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;

class Navigation
{
    public function locations(?array $theme = null): array
    {
        $locations = array_fill_keys(config('community.navigation_locations'), '');
        foreach ($locations as $id => &$label) {
            $label = __('site.community.location_'.$id);
        }
        unset($label);
        $theme ??= app(ExtensionManager::class)->activeTheme();
        if ($theme && $theme['target'] === 'site') {
            foreach ($theme['navigation_locations'] ?? [] as $id => $label) {
                $locations[$id] ??= __($label);
            }
        }

        return $locations;
    }

    public function configured(string $location): bool
    {
        return collect($this->links())->contains('location', $location);
    }

    public function label(array $labels): string
    {
        $locales = array_unique([
            app()->getLocale(),
            'en',
            app(LocalePolicy::class)->defaultLocale(),
            ...array_keys($labels),
        ]);
        foreach ($locales as $locale) {
            $label = $labels[$locale] ?? null;
            if (is_string($label) && trim($label) !== '') {
                return trim($label);
            }
        }

        return '';
    }

    public function tree(
        string $location,
        ?User $user,
        ?ViewCapabilities $capabilities = null,
        ?Request $request = null,
    ): array {
        $roles = $user?->effectiveRoles() ?? collect();
        $permissions = collect(app(PermissionAccess::class)->permissions($user, $roles));
        $allows = fn (?string $permission) => !$permission ||
            $permissions->contains('*') ||
            $permissions->contains($permission);
        $links = array_filter(
            $this->links(),
            fn ($link) => $link['enabled'] &&
                $link['location'] === $location &&
                ($link['visibility'] === 'all' ||
                    ($link['visibility'] === 'auth' && $user) ||
                    ($link['visibility'] === 'guest' && !$user)) &&
                $allows($link['permission']) &&
                (!$link['roles_restricted'] ||
                    $permissions->contains('*') ||
                    $roles->pluck('id')->intersect($link['role_ids'])->isNotEmpty()),
        );
        if (!$links) {
            return [];
        }
        $targets = app(NavigationTargets::class);
        $contentIds = array_values(array_filter(array_column($links, 'content_id')));
        $content = $contentIds ? $targets->contents($user, $contentIds)->keyBy('id') : collect();
        $routes = $targets->routes();
        foreach ($links as $id => &$link) {
            if ($link['destination_type'] === 'content') {
                $link['url'] = $content->get($link['content_id'])?->url();
            } elseif ($link['destination_type'] === 'route') {
                $target = $routes[$link['route_name']] ?? null;
                $capabilities ??= ViewCapabilities::forUser($user);
                $available = $capabilities->routeAvailable($link['route_name']);
                $link['url'] =
                    $target && $available && $allows($target['permission'] ?? null) ? route($link['route_name']) : null;
            }
            if (!NavigationTargets::validUrl($link['url'])) {
                unset($links[$id]);
            }
        }
        unset($link);

        $currentUrls = [];
        if ($request) {
            $url = $request->url();
            $path = parse_url($url, PHP_URL_PATH) ?: '/';
            $currentUrls = [$url, $url.'/', $path, rtrim($path, '/').'/', $request->fullUrl(), $request->getRequestUri()];
        }

        return $this->children($links, null, $currentUrls);
    }

    private function links(): array
    {
        return Cache::remember(
            'kraft.navigation',
            300,
            fn () => NavigationLink::with('roles:id')
                ->orderBy('position')
                ->orderBy('id')
                ->get()
                ->map(
                    fn (NavigationLink $link) => array_merge($link->attributesToArray(), [
                        'role_ids' => $link->roles->modelKeys(),
                    ]),
                )
                ->all(),
        );
    }

    private function children(array $links, ?int $parent, array $currentUrls, int $depth = 0): array
    {
        if ($depth >= 4) {
            return [];
        }
        $items = [];
        foreach ($links as $link) {
            if ($link['parent_id'] !== $parent) {
                continue;
            }
            $children = $this->children($links, $link['id'], $currentUrls, $depth + 1);
            $current = in_array($link['url'], $currentUrls, true);
            $items[] = [
                'label' => $this->label($link['labels']),
                'url' => $link['url'],
                'icon' => $link['icon'],
                'new_tab' => $link['new_tab'],
                'current' => $current,
                'active' => $current || array_any($children, fn (array $child) => $child['active']),
                'children' => $children,
            ];
        }

        return $items;
    }
}
