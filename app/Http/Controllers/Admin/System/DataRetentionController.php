<?php

namespace App\Http\Controllers\Admin\System;

use Illuminate\Support\Facades\Validator;
use App\Http\Controllers\Controller;
use App\Kraft\Audit;
use App\Kraft\Settings;
use App\Kraft\System\RetentionPolicy;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

class DataRetentionController extends Controller
{
    private const array CATEGORIES = ['history', 'temporary', 'technical'];

    public function index(RetentionPolicy $policy): View
    {
        $days = [];
        foreach (self::CATEGORIES as $category) {
            $days[$category] = $policy->days($category);
        }

        return view('admin.retention.index', [
            'days' => $days,
            'historyLimit' => $policy->historyLimit(),
        ]);
    }

    public function store(Request $request, Settings $settings): RedirectResponse
    {
        $rules = ['history_limit' => 'required|integer|between:1,1000'];
        foreach (self::CATEGORIES as $category) {
            $rules[$category.'_days'] = 'required|integer|between:1,3650';
        }
        $validator = Validator::make($request->all(), $rules);
        $data = array_map('intval', $validator->validate());
        DB::transaction(function () use ($settings, $data): void {
            foreach ($data as $key => $value) {
                $settings->set('retention.'.$key, $value);
            }
            Audit::record('retention.settings_saved', $data);
        });

        return redirect()->route('admin.retention.index')->with('status', __('retention.saved'));
    }
}
