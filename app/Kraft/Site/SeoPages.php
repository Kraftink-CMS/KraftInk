<?php

namespace App\Kraft\Site;

use App\Events\CollectingSeoPages;
use App\Http\Middleware\NoIndex;
use App\Models\Content\ContentEntry;
use Illuminate\Http\Request;
use Illuminate\Routing\Route as HttpRoute;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Uri;
use Symfony\Component\HttpKernel\Exception\MethodNotAllowedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

class SeoPages
{
    private ?Request $request = null;

    private ?string $locale = null;

    private array $pages = [];

    private ?array $contentPages = null;

    private array $targets = [];

    private array $matchedRoutes = [];

    public function all(): array
    {
        $pages = $this->routes();
        $this->contentPages ??= ContentEntry::published()
            ->publiclyIndexable()
            ->where('kind', 'page')
            ->orderBy('title')
            ->get(['id', 'title', 'slug'])
            ->mapWithKeys(fn (ContentEntry $entry) => ['content:'.$entry->id => $this->contentPage($entry)])
            ->all();

        return array_replace($pages, $this->contentPages);
    }

    public function forTargets(array $keys): array
    {
        $pages = $this->routes();
        $wanted = array_fill_keys(array_filter($keys, 'is_string'), true);
        if ($this->contentPages === null) {
            $pending = [];
            foreach (array_keys($wanted) as $key) {
                if (
                    !array_key_exists($key, $this->targets) &&
                    preg_match('/^content:([1-9][0-9]*)$/D', $key, $matches)
                ) {
                    $pending[$key] = $matches[1];
                }
            }
            if ($pending) {
                $entries = ContentEntry::published()
                    ->publiclyIndexable()
                    ->where('kind', 'page')
                    ->whereKey(array_values($pending))
                    ->get(['id', 'title', 'slug']);
                $this->targets += array_fill_keys(array_keys($pending), null);
                foreach ($entries as $entry) {
                    /** @var ContentEntry $entry */
                    $this->targets['content:'.$entry->id] = $this->contentPage($entry);
                }
            }
        }

        return array_intersect_key(array_replace($pages, $this->contentPages ?? array_filter($this->targets)), $wanted);
    }

    private function routes(): array
    {
        if ($this->request === request() && $this->locale === app()->getLocale()) {
            return $this->pages;
        }
        $event = new CollectingSeoPages();
        $identity = app(SiteIdentity::class);
        $event->add(
            'site.home.index',
            __('seo.targets.home'),
            defaults: [
                'title' => $identity->text('seo_title') ?: __('common.labels.home'),
                'description' => $identity->text('seo_description') ?:
                    ($identity->text('description') ?:
                    __('site.home.your_server_community_and_news')),
            ],
        );
        $event->add(
            'site.news.index',
            __('seo.targets.news_index'),
            defaults: [
                'title' => __('site.content.news'),
                'description' => __('site.content.news_help'),
            ],
        );
        $event->add(
            'site.news.show',
            __('seo.targets.news_show'),
            ['summary' => __('seo.variables.summary'), 'excerpt' => __('seo.variables.excerpt')],
            defaults: ['title' => '{title}', 'description' => '{description}'],
        );
        $event->add(
            'site.pages.show',
            __('seo.targets.content_pages'),
            ['summary' => __('seo.variables.summary'), 'excerpt' => __('seo.variables.excerpt')],
            defaults: ['title' => '{title}', 'description' => '{description}'],
        );
        event($event);
        $pages = [];
        foreach ($event->pages() as $key => $page) {
            $route = Route::getRoutes()->getByName($page['route']);
            if (!$route || !in_array('GET', $route->methods(), true) || self::requiresNoIndex($route)) {
                continue;
            }
            $variables = array_filter(
                $page['variables'],
                fn ($label, $variable) => is_string($variable) &&
                    preg_match('/^[a-z][a-z0-9_]{0,49}$/D', $variable) &&
                    !in_array($variable, SeoConfiguration::VARIABLES, true) &&
                    is_string($label),
                ARRAY_FILTER_USE_BOTH,
            );
            $path = preg_replace('~/?\{.*$~', '/*', '/'.ltrim($route->uri(), '/'));
            $pages[$key] = [
                ...$page,
                'path' => $path,
                'variables' => $variables,
                'aliases' => array_values(
                    array_filter($page['aliases'], fn ($alias) => is_string($alias) && Route::has($alias)),
                ),
            ];
        }
        $this->request = request();
        $this->locale = app()->getLocale();
        $this->contentPages = null;
        $this->targets = [];
        $this->matchedRoutes = [];

        return $this->pages = $pages;
    }

    public function current(Request $request): ?array
    {
        return array_find(
            $this->routes(),
            fn ($page) => !isset($page['parameters']) && $request->routeIs($page['route'], ...$page['aliases']),
        );
    }

    public static function requiresNoIndex(?HttpRoute $route): bool
    {
        return array_any(
            $route?->gatherMiddleware() ?? [],
            fn ($middleware) => is_string($middleware) &&
                ($middleware === NoIndex::class || preg_match('/^(auth|guest)(:|$)/', $middleware)),
        );
    }

    private function contentPage(ContentEntry $entry): array
    {
        return [
            'route' => 'site.pages.show',
            'label' => __('seo.targets.content_page', ['title' => $entry->title]),
            'path' => '/p/'.$entry->slug,
            'variables' => ['summary' => __('seo.variables.summary'), 'excerpt' => __('seo.variables.excerpt')],
            'aliases' => [],
            'parameters' => ['slug' => $entry->slug],
            'defaults' => ['title' => '{title}', 'description' => '{description}'],
        ];
    }

    public function matches(array $page, string $path, ?Request $request = null): bool
    {
        if (isset($page['parameters'])) {
            return $page['path'] === $path;
        }
        if ($request) {
            return $request->routeIs($page['route'], ...$page['aliases']);
        }
        if (!array_key_exists($path, $this->matchedRoutes)) {
            try {
                $url = (string) Uri::of(url('/'))->withPath('/'.ltrim($path, '/'));
                $this->matchedRoutes[$path] = Route::getRoutes()->match(Request::create($url))->getName();
            } catch (NotFoundHttpException|MethodNotAllowedHttpException) {
                $this->matchedRoutes[$path] = null;
            }
        }

        return in_array($this->matchedRoutes[$path], [$page['route'], ...$page['aliases']], true);
    }
}
