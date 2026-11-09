<?php

namespace App\Kraft;

use App\Kraft\Extensions\ExtensionManager;
use App\Models\Content\ContentEntry;
use App\Models\Accounts\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Route;

class NavigationTargets
{
    public function routes(): array
    {
        $entries = [
            ['route' => 'site.home.index', 'label' => 'common.labels.home'],
            ['route' => 'site.news.index', 'label' => 'common.labels.news'],
            ['route' => 'login', 'label' => 'common.labels.login'],
            ['route' => 'register', 'label' => 'site.auth.register.submit'],
            ['route' => 'account.index', 'label' => 'site.auth.account.title'],
            ['route' => 'account.notifications.index', 'label' => 'site.community.my_notices'],
        ];
        foreach (['public', 'footer'] as $location) {
            $entries = array_merge($entries, app(ExtensionManager::class)->navigation($location));
        }
        $result = [];
        foreach ($entries as $entry) {
            $route = Route::getRoutes()->getByName($entry['route']);
            if ($route && in_array('GET', $route->methods(), true) && !$route->parameterNames()) {
                $result[$entry['route']] = $entry;
            }
        }

        return $result;
    }

    /** @return Collection<int, ContentEntry> */
    public function contents(?User $user, ?array $ids = null, bool $published = true): Collection
    {
        return ContentEntry::visibleTo($user)
            ->when($published, fn ($query) => $query->published())
            ->when($ids !== null, fn ($query) => $query->whereIn('id', $ids))
            ->orderBy('title')
            ->get(['id', 'kind', 'locale', 'slug', 'title', 'published_at']);
    }

    public static function validUrl(?string $url): bool
    {
        if (!$url || preg_match('/[\\\\\x00-\x20\x7f]/', $url)) {
            return false;
        }
        if (str_starts_with($url, '/')) {
            return !str_starts_with(rawurldecode($url), '//') &&
                !str_contains(rawurldecode($url), '\\') &&
                !preg_match('/[\x00-\x1f\x7f]/', rawurldecode($url));
        }

        return filter_var($url, FILTER_VALIDATE_URL) &&
            in_array(strtolower(parse_url($url, PHP_URL_SCHEME) ?? ''), ['https', 'http'], true) &&
            !parse_url($url, PHP_URL_USER) &&
            !parse_url($url, PHP_URL_PASS);
    }
}
