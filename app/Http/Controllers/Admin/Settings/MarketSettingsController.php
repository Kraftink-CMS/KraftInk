<?php

namespace App\Http\Controllers\Admin\Settings;

use App\Http\Controllers\Controller;
use App\Kraft\Audit;
use App\Kraft\Extensions\Market;
use App\Kraft\Extensions\MarketAccess;
use App\Kraft\System\SensitiveAction;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class MarketSettingsController extends Controller
{
    public function index(Market $market, MarketAccess $access): View
    {
        $listing = collect($market->listingForNotifications()['sources'])->keyBy('id');
        $catalogues = [];
        foreach ($market->catalogues() as $id => $source) {
            $catalogues[] = $source + [
                'host' => parse_url($source['url'], PHP_URL_HOST),
                'has_key' => $source['supports_site_key'] && $access->hasKey($id),
                'state' => $listing[$id]['state'] ?? 'unavailable',
                'access' => $listing[$id]['access'] ?? null,
                'error' => $listing[$id]['error'] ?? null,
            ];
        }

        return view('admin.extensions.market', ['catalogues' => $catalogues]);
    }

    public function update(
        Request $request,
        Market $market,
        MarketAccess $access,
        SensitiveAction $security,
    ): RedirectResponse {
        $validator = Validator::make(
            $request->all(),
            [
                'source' => ['required', 'string', 'regex:/^[a-z][a-z0-9-]{0,49}$/D'],
                'site_key' => ['nullable', 'string', 'regex:/^[A-Za-z0-9._~-]{16,512}$/D'],
                'remove_key' => ['sometimes', 'boolean'],
            ],
            [
                'site_key.regex' => __('market.settings.key_invalid'),
            ],
        );
        $data = $validator->validate();
        $endpoint = $market->sources()[$data['source']] ?? null;
        if ($endpoint === null) {
            throw ValidationException::withMessages(['source' => __('market.settings.source_invalid')]);
        }
        if (!$market->catalogues()[$data['source']]['supports_site_key']) {
            throw ValidationException::withMessages(['source' => __('market.settings.source_public')]);
        }
        if (!empty($data['site_key'])) {
            $security->confirm($request);
        }
        $access->save($data['source'], $data['site_key'] ?? null, $request->boolean('remove_key'));
        if (!empty($data['site_key']) || $request->boolean('remove_key')) {
            Audit::record('market.access_configured', [
                'source' => $data['source'],
                'key_configured' => !$request->boolean('remove_key'),
            ]);
        }

        return redirect()
            ->route('admin.extensions.market.index')
            ->with(
                'status',
                __($request->boolean('remove_key') ? 'market.settings.key_removed' : 'market.settings.saved'),
            );
    }
}
