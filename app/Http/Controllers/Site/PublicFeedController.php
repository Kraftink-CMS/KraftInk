<?php

namespace App\Http\Controllers\Site;

use App\Http\Controllers\Controller;
use App\Kraft\LocalePolicy;
use App\Kraft\Site\SiteIdentity;
use App\Models\Content\ContentEntry;
use DOMDocument;
use DOMElement;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Str;
use DOMException;

class PublicFeedController extends Controller
{
    /**
     * @throws DOMException
     */
    public function show(Request $request, string $format): Response
    {
        if (!in_array($format, ['rss', 'atom'], true)) {
            abort(404);
        }
        $locale = $request->query('locale', app(LocalePolicy::class)->defaultLocale());
        if (!app(LocalePolicy::class)->valid($locale)) {
            abort(404);
        }
        app()->setLocale($locale);
        $entries = ContentEntry::published()
            ->visibleTo(null)
            ->where('kind', 'news')
            ->latest('published_at')
            ->limit(30)
            ->get();
        $identity = app(SiteIdentity::class);
        $title = $identity->text('name');
        $document = new DOMDocument('1.0', 'UTF-8');
        $updated = $entries->max('updated_at') ?? now();
        $namespace = $format === 'atom' ? 'http://www.w3.org/2005/Atom' : null;
        $root = new DOMElement($format === 'atom' ? 'feed' : 'rss', '', $namespace ?? '');
        $document->appendChild($root);
        if ($format === 'rss') {
            $root->setAttribute('version', '2.0');
            $channel = new DOMElement('channel');
            $root->appendChild($channel);
            $this->element($document, $channel, null, 'title', $title);
            $this->element($document, $channel, null, 'link', url('/'));
            $this->element($document, $channel, null, 'description', $identity->text('seo_description') ?: $title);
            $this->element($document, $channel, null, 'language', $locale);
            $this->element($document, $channel, null, 'lastBuildDate', $updated->toRssString());
        } else {
            $root->setAttributeNS('http://www.w3.org/XML/1998/namespace', 'xml:lang', $locale);
            $channel = $root;
            $this->element($document, $root, $namespace, 'title', $title);
            $this->element($document, $root, $namespace, 'id', url('/'));
            $this->element($document, $root, $namespace, 'updated', $updated->toAtomString());
            foreach (
                [
                    'alternate' => url('/'),
                    'self' => route('site.feeds.show', ['format' => $format, 'locale' => $locale]),
                ] as $relation => $href
            ) {
                $link = $this->element($document, $root, $namespace, 'link');
                $link->setAttribute('rel', $relation);
                $link->setAttribute('href', $href);
            }
        }
        foreach ($entries as $entry) {
            /** @var ContentEntry $entry */
            $item = $this->element($document, $channel, $namespace, $format === 'rss' ? 'item' : 'entry');
            $this->element($document, $item, $namespace, 'title', $entry->title);
            $identifier = url('/').'#news-'.$entry->id;
            $summary = Str::limit(
                trim(html_entity_decode(strip_tags($entry->body), ENT_QUOTES | ENT_HTML5, 'UTF-8')),
                500,
            );
            if ($format === 'rss') {
                $this->element($document, $item, null, 'link', route('site.news.show', $entry->slug));
                $guid = $this->element($document, $item, null, 'guid', $identifier);
                $guid->setAttribute('isPermaLink', 'false');
                $this->element($document, $item, null, 'pubDate', $entry->published_at->toRssString());
                $this->element($document, $item, null, 'description', $summary);
            } else {
                $this->element($document, $item, $namespace, 'id', $identifier);
                $this->element($document, $item, $namespace, 'published', $entry->published_at->toAtomString());
                $this->element($document, $item, $namespace, 'updated', $entry->updated_at->toAtomString());
                $link = $this->element($document, $item, $namespace, 'link');
                $link->setAttribute('href', route('site.news.show', $entry->slug));
                $this->element($document, $item, $namespace, 'summary', $summary);
                $author = $this->element($document, $item, $namespace, 'author');
                $this->element($document, $author, $namespace, 'name', $title);
            }
        }
        $response = response($document->saveXML(), 200, [
            'Content-Type' => ($format === 'rss' ? 'application/rss+xml' : 'application/atom+xml').'; charset=UTF-8',
            'Cache-Control' => 'public, max-age=60',
            'X-Content-Type-Options' => 'nosniff',
        ]);
        $response->setEtag(hash('sha256', $response->getContent()));
        $response->setLastModified($updated);
        $response->isNotModified($request);

        return $response;
    }

    /**
     * @throws DOMException
     */
    private function element(
        DOMDocument $document,
        DOMElement $parent,
        ?string $namespace,
        string $name,
        ?string $value = null,
    ): DOMElement {
        $element = new DOMElement($name, '', $namespace ?? '');
        $parent->appendChild($element);
        if ($value !== null) {
            $element->appendChild($document->createTextNode($value));
        }

        return $element;
    }
}
