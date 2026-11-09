<?php

namespace App\Http\Controllers\Admin\System;

use App\Http\Controllers\Controller;
use App\Kraft\Audit;
use App\Kraft\Settings;
use App\Kraft\System\MaintenancePolicy;
use App\Kraft\TextTranslations;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class MaintenanceController extends Controller
{
    public function index(Settings $settings): View
    {
        $values = [];
        foreach (
            [
                'enabled' => false,
                'message' => __('system.maintenance.default'),
                'mode' => 'all',
                'paths' => [],
                'exclusions' => [],
                'translations' => [],
            ] as $key => $default
        ) {
            $values[$key] = $settings->get('maintenance.'.$key, $default);
        }

        return view('admin.operations.maintenance.index', ['values' => $values]);
    }

    public function store(Request $request, Settings $settings, MaintenancePolicy $policy): RedirectResponse
    {
        $translations = app(TextTranslations::class);
        $data = $request->validate([
            'enabled' => 'required|boolean',
            'message' => 'required_if:enabled,1|nullable|string|max:2000',
            'mode' => ['required', Rule::in(['all', 'paths'])],
            'paths' => 'nullable|string|max:10000',
            'exclusions' => 'nullable|string|max:10000',
        ] + $translations->rules(['message' => ['max' => 2000]]));
        foreach (['paths', 'exclusions'] as $key) {
            $paths = [];
            foreach (preg_split('/\r\n|\n|\r/', $data[$key] ?? '') as $address) {
                $address = trim($address);
                if ($address === '') {
                    continue;
                }
                $path = $policy->normalizePattern($address, $request->getBaseUrl());
                if ($path === null) {
                    throw ValidationException::withMessages([$key => __('admin.operations.invalid_paths')]);
                }
                $paths[] = $path;
            }
            $data[$key] = array_values(array_unique($paths));
            if (count($data[$key]) > 50) {
                throw ValidationException::withMessages([$key => __('admin.operations.invalid_paths')]);
            }
        }
        if ($data['mode'] === 'paths' && !$data['paths']) {
            throw ValidationException::withMessages(['paths' => __('admin.operations.paths_required')]);
        }
        DB::transaction(function () use ($settings, $data, $request, $translations) {
            foreach (['message', 'mode', 'paths', 'exclusions'] as $key) {
                $settings->set('maintenance.'.$key, $data[$key] ?? '');
            }
            $settings->set('maintenance.enabled', $request->boolean('enabled'));
            $settings->set(
                'maintenance.translations',
                $translations->normalize($data['translations'] ?? [], $settings->get('maintenance.translations', []), [
                    'message' => ['max' => 2000],
                ]),
            );
            Audit::record('system.maintenance_changed', [
                'enabled' => $request->boolean('enabled'),
                'mode' => $data['mode'],
                'paths' => $data['paths'],
                'exclusions' => $data['exclusions'],
            ]);
        });

        return back()->with('status', __('admin.operations.saved'));
    }
}
