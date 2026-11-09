<?php

namespace App\Http\Controllers\Games;

use Symfony\Component\HttpKernel\Exception\HttpException;
use App\Http\Controllers\Controller;
use App\Kraft\Audit;
use App\Kraft\Extensions\ExtensionManager;
use App\Kraft\Extensions\ExtensionListing;
use App\Kraft\Extensions\ExtensionCatalogue;
use App\Kraft\Games\GameRegistry;
use App\Kraft\Games\GameSettings;
use App\Kraft\OAuth\OAuthRegistry;
use App\Kraft\Settings;
use App\Kraft\System\ListingPagination;
use App\Models\Accounts\GameIdentity;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;
use App\Kraft\Auth\AuthPolicy;
use App\Kraft\Games\Contracts\ResolvesPublicProfiles;
use App\Kraft\Games\Transports\TransportRegistry;

class GameController extends Controller
{
    public function admin(GameRegistry $registry, GameSettings $configuration, Settings $settings, ExtensionManager $manager, Request $request): View
    {
        if (
            !(
                $request->user()->hasPermission('settings.manage') ||
                $request->user()->hasPermission('extensions.manage')
            )
        ) {
            throw new HttpException(403);
        }
        $modules = array_filter($manager->all(), fn ($extension) => $extension['type'] === 'game');
        $catalogue =
            $request->boolean('catalogue') && $request->user()->hasPermission('extensions.manage')
                ? app(ExtensionCatalogue::class)->for('game')
                : null;
        if ($catalogue !== null) {
            $catalogue['items'] = app(ListingPagination::class)
                ->paginate($catalogue['items'], $request, 'catalogue_page')
                ->appends(['catalogue' => 1]);
        }
        $games = $configuration->forDisplay($registry->all(), $request);
        $capabilities = [];
        foreach ($games as $id => $game) {
            $transports = app(TransportRegistry::class)->metadata($id);
            $adapter = $registry->get($id);
            $features = $game['capabilities'] ?? [];
            $capabilities[$id] = [
                'verified_profile' => [
                    'available' => (bool) array_intersect(['account_verification', 'premium_ownership'], $features),
                    'ready' => $game['verified_profile_ready'] ?? true,
                    'help' => $game['verified_profile_help'] ?? null,
                ],
                'public_profile' => [
                    'available' => $adapter instanceof ResolvesPublicProfiles,
                    'ready' => $registry->supportsPublicProfiles($id),
                ],
                'server_status' => [
                    'available' => in_array('server_status', $features, true) ||
                        collect($transports)->contains(fn ($transport) => !empty($transport['status'])),
                ],
                'server_commands' => [
                    'available' => in_array('server_commands', $features, true) ||
                        collect($transports)->contains(fn ($transport) => !empty($transport['commands'])),
                ],
                'online_presence' => ['available' => in_array('online_presence', $features, true)],
            ];
        }

        return view('admin.games.index', [
            'games' => $games,
            'gameModules' => $modules,
            'extensionPage' => app(ExtensionListing::class)->paginate($modules, $request),
            'catalogue' => $catalogue,
            'diagnostics' => array_intersect_key($manager->diagnostics(), $modules),
            'safeMode' => (bool) config('kraft.safe_mode'),
            'settings' => $settings->all(),
            'gameFieldValues' => array_column($games, 'setting_values', 'id'),
            'gameCapabilities' => $capabilities,
        ]);
    }

    public function save(Request $request, GameSettings $configuration): RedirectResponse
    {
        $configuration->save($request);

        return back()->with('status', __('site.games.settings_saved'));
    }

    public function account(Request $request, GameRegistry $registry, OAuthRegistry $providers): View
    {
        $games = $registry->accountGames();
        foreach ($games as $id => $game) {
            if (!empty($game['connection_adapter']) && !$providers->enabledForAdapter($game['connection_adapter'])) {
                $games[$id]['connection_url'] = null;
            }
        }

        return view('default.user.account.games.index', [
            'games' => $games,
            'identities' => GameIdentity::query()->where('user_id', $request->user()->id)->get()->keyBy('game'),
        ]);
    }

    public function identity(Request $request, string $game, GameRegistry $registry): RedirectResponse
    {
        if (!array_key_exists($game, $registry->accountGames())) {
            abort(404);
        }
        app(AuthPolicy::class)->confirmCredentials($request);
        $data = $request->validate($registry->get($game)->identityRules());
        $registry->declareIdentity($request->user(), $game, $data);
        Audit::record('games.identity_declared', ['game' => $game]);

        return back()->with('status', __('site.games.identity_saved'));
    }
}
