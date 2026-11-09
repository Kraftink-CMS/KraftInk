<?php

namespace App\Http\Controllers\Site;

use App\Http\Controllers\Controller;
use App\Kraft\Site\Seo;
use App\Models\Content\ContentEntry;
use Illuminate\Http\Request;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Response;
use Illuminate\View\View;
use App\Kraft\HomeBlocks;
use App\Kraft\LocalePolicy;
use App\Kraft\Sitemap;
use App\Kraft\Slider;
use Exception;

class PublicController extends Controller
{
    public function home(): View
    {
        return view('default.home.index', [
            'news' => ContentEntry::published()
                ->visibleTo(auth()->user())
                ->with(['author', 'cover', 'privateCover'])
                ->where('kind', 'news')
                ->orderByDesc('is_pinned')
                ->latest('published_at')
                ->paginate(9),
            'slides' => app(Slider::class)->published(),
            'welcome' => app(HomeBlocks::class)->content(auth()->user()),
        ]);
    }

    public function locale(Request $request, string $locale): RedirectResponse
    {
        $cookie = app(LocalePolicy::class)->choose($request, $locale);

        return redirect('/')->withCookie($cookie);
    }

    /**
     * @throws Exception
     */
    public function sitemap(Request $request): Response
    {
        return app(Sitemap::class)->response($request);
    }

    public function robots(Seo $seo): Response
    {
        $paths = !$seo->globallyIndexable()
            ? ['/']
            : [
                '/admin',
                '/account',
                '/install',
                '/api/auth',
                '/api/link',
                '/oauth',
                '/reset-password',
                '/forgot-password',
                '/two-factor-challenge',
            ];

        return response(
            "User-agent: *\n".
                implode("\n", array_map(fn ($path) => 'Disallow: '.$path, $paths)).
                "\nSitemap: ".
                url('/sitemap.xml').
                "\n",
            200,
            ['Content-Type' => 'text/plain; charset=UTF-8', 'Cache-Control' => 'public, max-age=300'],
        );
    }
}
