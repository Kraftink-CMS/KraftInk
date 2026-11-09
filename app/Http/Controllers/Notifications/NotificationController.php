<?php

namespace App\Http\Controllers\Notifications;

use App\Kraft\System\TableQuery;
use App\Http\Controllers\Controller;
use App\Kraft\Audit;
use App\Kraft\Html;
use App\Kraft\SiteDates;
use App\Models\Notifications\CommunityNotice;
use App\Models\Accounts\Role;
use App\Models\Accounts\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use App\Kraft\TextTranslations;

class NotificationController extends Controller
{
    public function index(Request $request): View
    {
        $notices = CommunityNotice::visibleTo($request->user())
            ->withMax(['reads as read_at' => fn ($query) => $query->where('user_id', $request->user()->id)], 'read_at')
            ->latest('published_at')
            ->paginate(20);
        $hasUnread = CommunityNotice::unreadFor($request->user())->exists();

        return view('default.user.account.notifications.index', compact('notices', 'hasUnread'));
    }

    public function read(Request $request, int $notice): RedirectResponse
    {
        $item = CommunityNotice::visibleTo($request->user())->findOrFail($notice);
        $item->markReadBy($request->user());

        return back();
    }

    public function readAll(Request $request): RedirectResponse
    {
        CommunityNotice::markAllReadBy($request->user());

        return redirect()->route('account.notifications.index')->with('status', __('site.community.all_notices_read'));
    }

    public function admin(): View
    {
        return view('admin.notifications.index', [
            'notices' => TableQuery::paginate(
                CommunityNotice::query()->latest('id'),
                ['title', 'published_at', 'expires_at'],
                search: ['title'],
            ),
            'roles' => Role::query()->orderBy('position')->get(),
        ]);
    }

    public function create(): View
    {
        return view('admin.notifications.create', ['roles' => Role::query()->orderByDesc('position')->get()]);
    }

    public function store(Request $request): RedirectResponse
    {
        app(SiteDates::class)->normalize($request, ['published_at', 'expires_at']);
        $request->merge(['published_at' => $request->input('published_at') ?: now()->toDateTimeString()]);
        $data = $request->validate([
            'title' => 'required|string|max:160',
            'body' => 'required|string|max:50000',
            'url' => ['nullable', 'string', 'max:500', 'regex:~^(?:/(?!/)[^\r\n]*|https://[^\s]+)$~'],
            'audience' => ['required', Rule::in(['all', 'role', 'user'])],
            'role_id' => 'required_if:audience,role|nullable|integer|exists:roles,id',
            'email' => 'required_if:audience,user|nullable|email|exists:users,email',
            'published_at' => 'nullable|date',
            'expires_at' => 'nullable|date|after:published_at|after:now',
        ]);
        $audienceId = match ($data['audience']) {
            'role' => (int) $data['role_id'],
            'user' => User::query()->where('email', strtolower($data['email']))->value('id'),
            default => null,
        };
        $fields = ['title' => ['max' => 160], 'body' => ['max' => 50000, 'type' => 'richtext']];
        $translations = $request->validate(app(TextTranslations::class)->rules($fields));
        CommunityNotice::query()->create([
            'author_id' => $request->user()->id,
            'title' => $data['title'],
            'body' => Html::clean($data['body']),
            'translations' => app(TextTranslations::class)->normalize($translations['translations'] ?? [], [], $fields),
            'url' => $data['url'] ?? null,
            'audience' => $data['audience'],
            'audience_id' => $audienceId,
            'published_at' => $data['published_at'] ?? now(),
            'expires_at' => $data['expires_at'] ?? null,
        ]);
        Audit::record('notification.published', ['audience' => $data['audience']]);

        return redirect()->route('admin.notifications.index')->with('status', __('site.community.notice_published'));
    }

    public function destroy(CommunityNotice $notice): RedirectResponse
    {
        $notice->delete();
        Audit::record('notification.deleted', ['id' => $notice->id]);

        return back();
    }
}
