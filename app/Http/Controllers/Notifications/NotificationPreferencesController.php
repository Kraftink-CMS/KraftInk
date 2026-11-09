<?php

namespace App\Http\Controllers\Notifications;

use App\Kraft\Notifications\NotificationPreferences;
use Illuminate\Http\Request;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;

class NotificationPreferencesController
{
    public function edit(Request $request, NotificationPreferences $preferences): View
    {
        return view('default.user.account.notifications.preferences', ['types' => $preferences->for($request->user())]);
    }

    public function update(Request $request, NotificationPreferences $preferences): RedirectResponse
    {
        $data = $request->validate([
            'preferences' => 'nullable|array|max:200',
            'preferences.*' => 'array:type,channels',
            'preferences.*.type' => 'required|string|max:120|distinct',
            'preferences.*.channels' => 'required|array:native,email',
            'preferences.*.channels.*' => 'required|boolean',
        ]);
        $values = [];
        foreach ($data['preferences'] ?? [] as $entry) {
            $values[$entry['type']] = $entry['channels'];
        }
        $preferences->save($request->user(), $values);

        return back()->with('status', __('notifications.saved'));
    }
}
