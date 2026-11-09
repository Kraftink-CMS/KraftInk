<?php

namespace App\Http\Controllers\Admin\System;

use App\Http\Controllers\Controller;
use App\Kraft\Audit;
use App\Kraft\System\CacheOperations;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use Throwable;

class CacheController extends Controller
{
    public function index(): View
    {
        return view('admin.operations.cache.index', [
            'actions' => CacheOperations::ACTIONS,
            'cached' => ['config' => app()->configurationIsCached(), 'routes' => app()->routesAreCached()],
        ]);
    }

    public function run(Request $request, CacheOperations $operations): RedirectResponse
    {
        $data = $request->validate([
            'operation' => ['required', Rule::in(CacheOperations::ACTIONS)],
        ]);
        try {
            $operations->run($data['operation']);
        } catch (Throwable $exception) {
            Audit::record('system.cache_failed', ['operation' => $data['operation'], 'exception' => $exception::class]);

            return back()->withErrors(['operation' => __('admin.operations.cache_failed')]);
        }

        return back()->with('status', __('admin.operations.cache_done'));
    }
}
