<?php

namespace KraftInk\Plugins\Network\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Kraft\Audit;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use KraftInk\Plugins\Network\Services\Configuration;

class NetworkController extends Controller
{
    public function index(Configuration $configuration): View
    {
        return view('network::admin.index', ['network' => $configuration->get()]);
    }

    public function save(Request $request, Configuration $configuration): RedirectResponse
    {
        $data = $request->validate([
            'mode' => ['required', Rule::in(['direct', 'cloudflare', 'custom'])],
            'proxies' => 'exclude_unless:mode,custom|nullable|string|max:10000',
        ]);
        $proxies = null;
        if ($data['mode'] === 'custom') {
            $proxies = array_values(array_filter(preg_split('/[\s,]+/', $data['proxies'] ?? '')));
            foreach ($proxies as $proxy) {
                if (!Configuration::validProxy($proxy)) {
                    throw ValidationException::withMessages(['proxies' => __('network::admin.invalid_proxy')]);
                }
            }
        }
        $configuration->save($data['mode'], $proxies);
        Audit::record('network.configured');

        return back()->with('status', __('network::admin.saved'));
    }
}
