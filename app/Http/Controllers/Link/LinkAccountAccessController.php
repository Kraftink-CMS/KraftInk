<?php

namespace App\Http\Controllers\Link;

use App\Kraft\System\TableQuery;
use App\Http\Controllers\Controller;
use App\Kraft\Link\Accounts\AccountTokens;
use App\Kraft\System\SensitiveAction;
use App\Models\Servers\LinkAccountToken;
use App\Models\Servers\Server;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class LinkAccountAccessController extends Controller
{
    public function index(Server $server, Request $request, AccountTokens $tokens): View
    {
        return view('admin.servers.link.accounts.index', [
            'server' => $server,
            'tokens' => TableQuery::paginate(
                LinkAccountToken::query()->where('server_id', $server->id)->latest('created_at'),
                ['name', 'created_at', 'expires_at', 'last_used_at'],
                search: ['name'],
            ),
            'scopes' => $tokens->available($request->user()),
        ]);
    }

    public function issue(Server $server, Request $request, AccountTokens $tokens): RedirectResponse
    {
        $data = $request->validate([
            'name' => 'required|string|max:120',
            'scopes' => 'required|array|min:1|max:8',
            'scopes.*' => ['required', 'string', 'distinct', Rule::in(array_keys(AccountTokens::SCOPES))],
            'days' => 'required|integer|between:1,90',
        ]);
        app(SensitiveAction::class)->confirm($request);
        [, $raw] = $tokens->issue($server, $request->user(), $data['name'], $data['scopes'], (int) $data['days']);

        return back()->with('link.accounts.token', $raw)->with('status', __('admin.servers.accounts.created'));
    }

    public function revoke(Server $server, string $token, Request $request, AccountTokens $tokens): RedirectResponse
    {
        $tokens->revoke($server, $token, $request->user());

        return back()->with('status', __('admin.servers.accounts.revoked'));
    }
}
