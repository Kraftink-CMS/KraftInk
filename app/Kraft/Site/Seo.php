<?php

namespace App\Kraft\Site;

use App\Kraft\LocalePolicy;
use App\Kraft\Settings;
use App\Kraft\SiteDates;
use App\Kraft\TextTranslations;
use App\Models\Content\ContentEntry;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class Seo
{
    public const string NO_INDEX = 'noindex,nofollow';

    public const string ROBOTS_ATTRIBUTE = 'kraft.seo.robots';

    public function __construct(private Settings $settings, private SiteIdentity $identity)
    {
    }

    public static function text(?string $value): string
    {
        $value = preg_replace('~<(script|style)\b[^>]*>.*?</\1>~is', '', $value ?? '');
        $value = preg_replace('~</(?:p|div|h[1-6]|li|section|article)>|<br\s*/?>~i', ' ', $value);
        $value = html_entity_decode(strip_tags($value), ENT_QUOTES | ENT_HTML5, 'UTF-8');

        return trim(preg_replace('/[\s\p{Z}\x{200B}\x{FEFF}]+/u', ' ', $value) ?? '');
    }

    public static function excerpt(?string $value, int $length = 160): string
    {
        return Str::limit(self::text($value), $length, '…');
    }

    public function metadata(array $sections, array $context = [], ?Request $request = null): array
    {
        $request ??= request();
        $site = self::text($this->identity->text('name')) ?: 'KraftInk';
        $definition = app(SeoPages::class)->current($request);
        $entry = ($context['seoEntry'] ?? null) instanceof ContentEntry ? $context['seoEntry'] : null;
        $options = $context['page'] ?? [];
        $pageTitle = self::text($options['title'] ?? '');
        $pageDescription = self::text($options['description'] ?? '');
        $title = isset($options['title']) ? $pageTitle : self::text($sections['title'] ?? '');
        $description = match (true) {
            isset($options['description']) => $pageDescription,
            isset($options['excerpt']) => self::excerpt($options['excerpt']),
            default => self::text($sections['description'] ?? ''),
        };
        $siteDescription = self::text($this->identity->text('description'));
        $explicitTitle = $entry ? '' : self::text($sections['seo_title'] ?? $options['seo_title'] ?? '');
        $explicitDescription = $entry ? '' : self::text($sections['seo_description'] ?? $options['seo_description'] ?? '');
        $contentVariables = [];
        if ($entry) {
            $title = self::text($entry->title);
            $summary = self::text($entry->summary);
            $excerpt = self::excerpt($entry->body);
            $description = $summary !== '' ? $summary : $excerpt;
            $contentVariables = ['summary' => $summary, 'excerpt' => $excerpt];
        }
        $title = $title !== '' ? $title : $site;
        $description = $description !== '' ? $description : $siteDescription;
        $pagination = [];
        foreach ((array) ($options['page_parameter'] ?? 'page') as $parameter) {
            $pagination[$parameter] = $this->page($request, $parameter);
        }
        $page = array_find($pagination, fn (int $number) => $number > 1) ?? 1;
        $canonical = $this->canonical($sections['canonical'] ?? $options['canonical'] ?? '', $request, $pagination);
        $variables = $this->variables($title, $description, $canonical, $request, $page);
        foreach ($definition['variables'] ?? [] as $name => $label) {
            $value = $contentVariables[$name] ?? ($context['variables'][$name] ?? '');
            if (is_scalar($value) || $value === null) {
                $variables['{'.$name.'}'] = match (true) {
                    isset($contentVariables[$name]) => $value,
                    in_array($name, $definition['excerptVariables'] ?? [], true) => self::excerpt((string) $value),
                    default => self::text((string) $value),
                };
            }
        }
        foreach (['title', 'description'] as $field) {
            if (filled($definition['defaults'][$field] ?? null)) {
                $variables['{'.$field.'}'] = $this->render($definition['defaults'][$field], $variables);
            }
        }
        if ($pageTitle !== '') {
            $variables['{title}'] = $pageTitle;
        }
        if ($pageDescription !== '') {
            $variables['{description}'] = $pageDescription;
        }
        $rule = $this->matchingRule($variables['{path}'], $request);
        $robots = $this->robots(
            ($sections['robots'] ?? '').','.($options['robots'] ?? ''),
            $rule,
            $this->privatePage($request, $entry),
        );
        $indexable = $this->allowsIndexing($robots);
        $type = $sections['og_type'] ?? $options['og_type'] ?? ($entry?->kind === 'news' ? 'article' : 'website');

        return $this->textMetadata($variables, $rule, $explicitTitle, $explicitDescription, $page) + [
            'canonical' => $canonical,
            'site' => $site,
            'locale' => str_replace('-', '_', app()->getLocale()),
            'robots' => $robots,
            'type' => in_array($type, ['website', 'article', 'profile'], true) ? $type : 'website',
            'image' => $this->socialImage($sections, $options, $entry),
            'alternates' => $indexable ? $this->alternates($options['alternates'] ?? [], $canonical) : [],
            'structured_data' => $indexable ? $options['structured_data'] ?? [] : [],
        ];
    }

    public function globallyIndexable(): bool
    {
        return $this->allowsIndexing($this->robots('', null, false));
    }

    public function indexable(string $url): bool
    {
        $path = parse_url($url, PHP_URL_PATH) ?: '/';
        $base = rtrim(parse_url(url('/'), PHP_URL_PATH) ?: '', '/');
        if ($base !== '' && str_starts_with($path, $base.'/')) {
            $path = substr($path, strlen($base));
        } elseif ($path === $base) {
            $path = '/';
        }

        return $this->allowsIndexing($this->robots('', $this->matchingRule($path), false));
    }

    public function formatTitle(string $title): string
    {
        $request = request();

        return $this->render(
            $this->globalText('title', SeoConfiguration::DEFAULT_TITLE),
            $this->variables(self::text($title), '', url('/'.ltrim($request->path(), '/')), $request, $this->page($request, 'page')),
        ) ?:
            self::text($title);
    }

    private function textMetadata(
        array $variables,
        ?array $rule,
        string $explicitTitle,
        string $explicitDescription,
        int $page,
    ): array {
        $site = $variables['{website_name}'];
        $ruleStructure = $this->ruleText($rule, 'title_template');
        $titleTemplate =
            $ruleStructure !== '' ? $ruleStructure : $this->globalText('title', SeoConfiguration::DEFAULT_TITLE);
        $descriptionTemplate = $this->globalText('description', SeoConfiguration::DEFAULT_DESCRIPTION);
        $ruleTitle = $this->ruleText($rule, 'title');
        if ($explicitTitle !== '' || $ruleTitle !== '') {
            $variables['{title}'] = $this->render($explicitTitle !== '' ? $explicitTitle : $ruleTitle, $variables);
        }
        if ($variables['{title}'] === $site && $titleTemplate === SeoConfiguration::DEFAULT_TITLE) {
            $title = $variables['{title}'];
        } else {
            $title = $this->render($titleTemplate, $variables);
        }
        $title = $title !== '' ? $title : $site;
        if (
            $page > 1 &&
            !str_contains($titleTemplate, '{page}') &&
            !str_contains($explicitTitle !== '' ? $explicitTitle : $ruleTitle, '{page}')
        ) {
            $title = __('seo.pagination_title', ['title' => $title, 'page' => $page]);
        }
        $ruleDescription = $this->ruleText($rule, 'description');
        $description = $this->render(
            $explicitDescription !== ''
                ? $explicitDescription
                : ($ruleDescription !== ''
                    ? $ruleDescription
                    : $descriptionTemplate),
            $variables,
        );

        return [
            'title' => $title,
            'description' => $description,
            'keywords' => $this->render($this->ruleText($rule, 'keywords') ?: $this->globalText('keywords'), $variables),
        ];
    }

    private function socialImage(array $sections, array $options, ?ContentEntry $entry): ?string
    {
        $image = $this->webUrl($sections['og_image'] ?? $options['og_image'] ?? '');
        if (
            $entry &&
            !$entry->cover_image_id &&
            !array_key_exists('og_image', $sections) &&
            !array_key_exists('og_image', $options)
        ) {
            $image = $entry->coverUrl();
        }
        $image ??= $this->webUrl($this->settings->get('seo.image'));
        if ($image === null && ($logo = $this->identity->logo())) {
            $image = route('site.media.show', $logo);
        }

        return $image;
    }

    private function variables(string $title, string $description, string $canonical, Request $request, int $page): array
    {
        $site = self::text($this->identity->text('name')) ?: 'KraftInk';

        return [
            '{title}' => $title,
            '{website_name}' => $site,
            '{description}' => $description,
            '{site_description}' => self::text($this->identity->text('description')),
            '{url}' => $canonical,
            '{path}' => '/'.ltrim($request->path(), '/'),
            '{locale}' => app()->getLocale(),
            '{year}' => now(app(SiteDates::class)->timezone())->format('Y'),
            '{page}' => (string) $page,
        ];
    }

    private function globalText(string $field, string $fallback = ''): string
    {
        $key = match ($field) {
            'title' => 'seo.title_template',
            'description' => 'seo.description_template',
            default => 'seo.'.$field,
        };

        /** @var TextTranslations $translations */
        $translations = app(TextTranslations::class);
        $value = $translations->resolve(
            (string) $this->settings->get($key, ''),
            $this->settings->get('seo.translations', []),
            $field,
        );

        return $value !== '' ? $value : $fallback;
    }

    private function ruleText(?array $rule, string $field): string
    {
        if (!$rule) {
            return '';
        }

        /** @var TextTranslations $translations */
        $translations = app(TextTranslations::class);

        return $translations->resolve((string) ($rule[$field] ?? ''), $rule['translations'] ?? [], $field);
    }

    private function matchingRule(string $path, ?Request $request = null): ?array
    {
        $path = SeoConfiguration::normalizePath($path);
        if ($path === null) {
            return null;
        }
        $matched = null;
        $length = -1;
        $rules = $this->settings->get('seo.pages', []);
        if (!$rules) {
            return null;
        }
        $pages = app(SeoPages::class);
        $targets = $pages->forTargets(array_column($rules, 'target'));
        foreach ($rules as $rule) {
            $pattern = is_array($rule) ? $rule['path'] ?? '' : '';
            if (!is_string($pattern) || $pattern === '') {
                continue;
            }
            if (isset($rule['target'])) {
                $target = $targets[$rule['target']] ?? null;
                if (!$target || !$pages->matches($target, $path, $request)) {
                    continue;
                }
                if (isset($target['parameters'])) {
                    return $rule;
                }
                $matched = $rule;
                $length = max($length, strlen($pattern));

                continue;
            }
            if ($path === $pattern) {
                return $rule;
            }
            $prefix = str_ends_with($pattern, '/*') ? substr($pattern, 0, -1) : null;
            if ($prefix !== null && str_starts_with($path, $prefix) && strlen($prefix) > $length) {
                $matched = $rule;
                $length = strlen($prefix);
            }
        }

        return $matched;
    }

    private function render(string $template, array $variables): string
    {
        return trim(preg_replace('/[\s\p{Z}\x{200B}\x{FEFF}]+/u', ' ', strtr(self::text($template), $variables)) ?? '');
    }

    private function page(Request $request, string $parameter): int
    {
        $page = $request->query($parameter);

        return is_string($page) && preg_match('/^[1-9][0-9]{0,6}$/D', $page) ? (int) $page : 1;
    }

    private function canonical(string $provided, Request $request, array $pagination): string
    {
        $url = $this->webUrl($provided) ?? url('/'.ltrim($request->path(), '/'));
        $parts = parse_url($url);
        parse_str($parts['query'] ?? '', $query);
        foreach (array_keys($query) as $key) {
            if (
                str_starts_with(strtolower($key), 'utm_') ||
                in_array(strtolower($key), ['fbclid', 'gclid', 'msclkid'], true)
            ) {
                unset($query[$key]);
            }
        }
        foreach ($pagination as $parameter => $page) {
            if ($page > 1) {
                $query[$parameter] = $page;
            } elseif (isset($query[$parameter]) && (string) $query[$parameter] === '1') {
                unset($query[$parameter]);
            }
        }
        $base = preg_split('/[?#]/', $url, 2)[0];

        return $base.($query ? '?'.http_build_query($query, '', '&', PHP_QUERY_RFC3986) : '');
    }

    private function robots(string $provided, ?array $rule, bool $private): string
    {
        $policies = [
            strtolower((string) $this->settings->get('seo.robots', 'index,follow')),
            strtolower($provided),
            strtolower($rule['robots'] ?? ''),
        ];
        $tokens = array_map('trim', explode(',', implode(',', $policies)));

        return ($private || in_array('noindex', $tokens, true) || in_array('none', $tokens, true)
            ? 'noindex'
            : 'index').
            ','.
            ($private || in_array('nofollow', $tokens, true) || in_array('none', $tokens, true)
                ? 'nofollow'
                : 'follow');
    }

    private function allowsIndexing(string $robots): bool
    {
        return str_starts_with($robots, 'index,');
    }

    private function privatePage(Request $request, ?ContentEntry $entry): bool
    {
        if ($entry && !$entry->isPubliclyIndexable()) {
            return true;
        }
        if (
            $request->routeIs(
                'account.*',
                'admin.*',
                'login',
                'register',
                'password.*',
                'verification.*',
                'two-factor.*',
            )
        ) {
            return true;
        }
        if (SeoPages::requiresNoIndex($request->route())) {
            return true;
        }

        return array_any(['q', 'search'], fn ($parameter) => filled($request->query($parameter)));
    }

    private function alternates(array $alternates, string $canonical): array
    {
        if (count($alternates) < 2 || !app(LocalePolicy::class)->allowsChoice()) {
            return [];
        }

        $urls = [];
        foreach (array_intersect_key($alternates, config('kraft.locales')) as $locale => $alternate) {
            $url = is_string($alternate) ? $this->webUrl($alternate) : null;
            if ($url === null || str_contains($url, '#') || !$this->indexable($url)) {
                continue;
            }
            $urls[$locale] = $url;
        }

        if (($urls[app()->getLocale()] ?? null) !== $canonical || count(array_unique($urls)) !== count($urls)) {
            return [];
        }

        return count($urls) >= 2 ? $urls : [];
    }

    private function webUrl(?string $value): ?string
    {
        $value = trim(html_entity_decode($value ?? '', ENT_QUOTES | ENT_HTML5, 'UTF-8'));
        if (str_starts_with($value, '/') && !str_starts_with($value, '//')) {
            return url($value);
        }
        $parts = parse_url($value);

        return $parts &&
            in_array($parts['scheme'] ?? '', ['http', 'https'], true) &&
            !empty($parts['host']) &&
            !isset($parts['user']) &&
            !isset($parts['pass'])
            ? $value
            : null;
    }
}
