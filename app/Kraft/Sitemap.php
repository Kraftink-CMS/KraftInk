<?php

namespace App\Kraft;

use Illuminate\Support\Facades\Validator;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use App\Events\CollectingSitemapEntries;
use App\Models\Content\ContentEntry;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use SimpleXMLElement;
use Throwable;
use App\Kraft\Site\Seo;
use Illuminate\Http\Response;
use Exception;

class Sitemap
{
    /**
     * @throws Exception
     */
    public function response(Request $request): Response
    {
        $validator = Validator::make($request->all(), ['page' => 'nullable|integer|min:1|max:100000']);
        $data = $validator->validate();
        $size = max(1, min(50000, (int) config('kraft.sitemap_page_size', 40000)));
        $page = $data['page'] ?? null;
        $offset = (($page ?? 1) - 1) * $size;
        $entries = [];
        $seen = [];
        $count = 0;
        foreach ($this->entries() as $entry) {
            $entry = $this->normalize($entry);
            if ($entry === null) {
                continue;
            }
            if ($count >= $offset && $count < $offset + $size && !isset($seen[$entry['url']])) {
                $entries[] = $entry;
                $seen[$entry['url']] = true;
            }
            $count++;
            if ($page !== null && $count >= $offset + $size) {
                break;
            }
        }
        if ($page !== null && $entries === []) {
            throw new NotFoundHttpException();
        }
        $index = $page === null && $count > $size;
        $root = $index ? 'sitemapindex' : 'urlset';
        $xml = new SimpleXMLElement(
            '<?xml version="1.0" encoding="UTF-8"?><'.
                $root.
                ' xmlns="http://www.sitemaps.org/schemas/sitemap/0.9"/>',
        );
        if ($index) {
            for ($number = 1; $number <= (int) ceil($count / $size); $number++) {
                $xml->addChild('sitemap')->addChild(
                    'loc',
                    htmlspecialchars(url('/sitemap.xml').'?page='.$number, ENT_XML1),
                );
            }
        } else {
            foreach ($entries as $entry) {
                $node = $xml->addChild('url');
                $node->addChild('loc', htmlspecialchars($entry['url'], ENT_XML1));
                if ($entry['modified']) {
                    $node->addChild('lastmod', $entry['modified']);
                }
            }
        }

        return response($xml->asXML(), 200, [
            'Content-Type' => 'application/xml; charset=UTF-8',
            'Cache-Control' => 'public, max-age=60',
        ]);
    }

    private function entries(): iterable
    {
        $locale = app(LocalePolicy::class)->defaultLocale();
        app()->setLocale($locale);
        yield ['url' => url('/')];
        foreach (
            ContentEntry::published()
                ->publiclyIndexable()
                ->whereIn('kind', ['news', 'page'])
                ->select(['id', 'kind', 'slug', 'updated_at'])
                ->orderBy('id')
                ->cursor() as $item
        ) {
            /** @var ContentEntry $item */
            yield [
                'url' => url(($item->kind === 'news' ? '/news/' : '/p/').$item->slug),
                'modified' => $item->updated_at,
            ];
        }
        $event = new CollectingSitemapEntries($locale);
        event($event);
        yield from $event->entries();
    }

    private function normalize(mixed $entry): ?array
    {
        if (!is_array($entry) || !is_string($entry['url'] ?? null) || strlen($entry['url']) > 2000) {
            return null;
        }
        $url =
            str_starts_with($entry['url'], '/') && !str_starts_with($entry['url'], '//')
                ? url($entry['url'])
                : $entry['url'];
        $parts = parse_url($url);
        $origin = parse_url(url('/'));
        if (
            $parts === false ||
            isset($parts['fragment']) ||
            !app(Redirects::class)->validDestination($url) ||
            strcasecmp($parts['host'] ?? '', $origin['host'] ?? '') !== 0 ||
            ($parts['scheme'] ?? '') !== ($origin['scheme'] ?? '') ||
            ($parts['port'] ?? null) !== ($origin['port'] ?? null) ||
            (($parts['path'] ?? '/') !== '/' && app(Redirects::class)->path($parts['path'] ?? '/') === null)
        ) {
            return null;
        }
        if (!app(Seo::class)->indexable($entry['url'])) {
            return null;
        }
        try {
            $modified = isset($entry['modified']) ? Carbon::parse($entry['modified'])->toAtomString() : null;
        } catch (Throwable) {
            return null;
        }

        return ['url' => $url, 'modified' => $modified];
    }
}
