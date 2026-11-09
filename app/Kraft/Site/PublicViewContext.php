<?php

namespace App\Kraft\Site;

use App\Kraft\Appearance;
use App\Kraft\Auth\PermissionAccess;
use App\Kraft\CommunityConnection;
use App\Kraft\Extensions\ExtensionManager;
use App\Kraft\Games\GameRegistry;
use App\Kraft\HomeBlocks;
use App\Kraft\Navigation;
use App\Kraft\Settings;
use App\Models\Notifications\CommunityNotice;
use App\Models\Accounts\User;
use Illuminate\Http\Request;

class PublicViewContext
{
    private ?Request $request = null;

    private array $context = [];

    public function __construct(
        private readonly Settings $settings,
        private readonly Navigation $navigation,
        private readonly ExtensionManager $extensions,
        private readonly SiteIdentity $identity,
    ) {
    }

    public function data(Request $request): array
    {
        if ($this->request === $request) {
            return $this->context;
        }
        $user = $request->user();
        $capabilities = ViewCapabilities::forRequest($request);
        $theme = $this->extensions->activeTheme();
        /** @var Appearance $appearancePolicy */
        $appearancePolicy = app(Appearance::class);
        $appearance = $appearancePolicy->resolve($request, $theme);
        $games = app(GameRegistry::class);
        $homeHeading = app(HomeBlocks::class)->heading();
        $communityConnection = app(CommunityConnection::class)->data();
        $publicSite = [
            'name' => $this->identity->text('name'),
            'description' => $this->identity->text('description'),
            'homeTitle' => $homeHeading['title'],
            'homeDescription' => $homeHeading['description'],
            'communityAddress' => $communityConnection['address'],
            'gameName' => $games->all()[$games->activeId()]['name'] ?? '',
            'extensionHeaderLinks' => $this->extensionLinks('public', $user, $capabilities),
            'extensionFooterLinks' => $this->extensionLinks('footer', $user, $capabilities),
            'copyright' => $this->identity->copyright(),
            'logo' => $this->identity->logo(),
            'unreadNoticeCount' => $user ? CommunityNotice::unreadFor($user)->count() : 0,
        ];
        $this->request = $request;
        $themeMenus = [];
        foreach (array_keys($theme['navigation_locations'] ?? []) as $location) {
            $themeMenus[$location] = $this->navigation->tree($location, $user, $capabilities, $request);
        }

        return $this->context = [
            'publicSite' => $publicSite,
            'communityConnection' => $communityConnection,
            'capabilities' => $capabilities,
            'siteSettings' => $this->settings,
            'siteName' => $publicSite['name'],
            'themeManifest' => $theme,
            'activeTheme' => $theme['id'] ?? '',
            'appearance' => $appearance,
            'headerLinks' => $this->navigation->tree('header', $user, $capabilities, $request),
            'footerLinks' => $this->navigation->tree('footer', $user, $capabilities, $request),
            'themeMenus' => $themeMenus,
        ];
    }

    private function extensionLinks(string $area, ?User $user, ViewCapabilities $capabilities): array
    {
        $links = [];
        foreach ($this->extensions->navigation($area) as $link) {
            if (
                !$capabilities->routeAvailable($link['route']) ||
                (!empty($link['permission']) && !app(PermissionAccess::class)->allows($user, $link['permission']))
            ) {
                continue;
            }
            $links[] = [
                'label' => __($link['label']),
                'url' => route($link['route']),
                'active' => request()->routeIs($link['route']),
            ];
        }

        return $links;
    }
}
