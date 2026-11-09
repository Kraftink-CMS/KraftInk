<?php

namespace App\Http\Controllers\Account;

use Symfony\Component\HttpKernel\Exception\HttpException;
use App\Kraft\System\TableQuery;
use App\Http\Controllers\Controller;
use App\Kraft\Audit;
use App\Kraft\Auth\AvatarPolicy;
use App\Kraft\Site\ViewCapabilities;
use App\Kraft\System\PersonalData;
use App\Kraft\System\SensitiveAction;
use App\Models\Accounts\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedJsonResponse;
use App\Kraft\Auth\AccountPolicy;

class PrivacyController extends Controller
{
    public function index(Request $request): View
    {
        $capabilities = ViewCapabilities::forRequest($request, withExtensions: false);

        return view('default.user.account.privacy.index', [
            'user' => $request->user(),
            'hasRetainedAvatar' => $request->user()->avatar_path && !app(AvatarPolicy::class)->allowsUploads(),
            'sessions' => $capabilities->canListSessions
                ? TableQuery::paginate(
                    DB::table('sessions')
                        ->where('user_id', $request->user()->id)
                        ->select(['id', 'ip_address', 'user_agent', 'last_activity'])
                        ->orderByDesc('last_activity')
                        ->orderBy('id'),
                    ['ip_address', 'user_agent', 'last_activity'],
                    pageName: 'sessions_page',
                    search: ['ip_address', 'user_agent'],
                )
                : collect(),
        ]);
    }

    public function export(
        Request $request,
        SensitiveAction $security,
        PersonalData $personalData,
    ): StreamedJsonResponse {
        $security->confirm($request);
        $data = $personalData->export($request->user());
        Audit::record('account.data_exported');

        return response()->streamJson(
            $data,
            200,
            [
                'Cache-Control' => 'private, no-store',
                'Content-Disposition' => 'attachment; filename="kraftink-personal-data-'.now()->format('Y-m-d').'.json"',
            ],
            JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES,
        );
    }

    public function destroy(Request $request, SensitiveAction $security, PersonalData $personalData): RedirectResponse
    {
        if (!app(AccountPolicy::class)->allows('deletion')) {
            throw new HttpException(403);
        }
        $request->validate(['confirmation' => 'accepted']);

        $security->confirm($request);
        $personalData->anonymize($request->user());
        Auth::logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect('/')->with('status', __('site.account.privacy.account_closed'));
    }

    public function revokeOthers(Request $request): RedirectResponse
    {
        $user = $request->user();
        DB::transaction(function () use ($user, $request) {
            $locked = User::query()->lockForUpdate()->findOrFail($user->id);
            $version = (int) $locked->auth_session_version + 1;
            $locked->forceFill(['auth_session_version' => $version, 'remember_token' => Str::random(80)])->save();
            $user->forceFill(['auth_session_version' => $version]);
            $request->session()->put('auth.version', $version);
            if (config('session.driver') === 'database') {
                DB::table('sessions')
                    ->where('user_id', $user->id)
                    ->where('id', '!=', $request->session()->getId())
                    ->delete();
            }
        });
        Audit::record('account.sessions_revoked');

        return back()->with('status', __('site.account.privacy.sessions_revoked'));
    }

    public function revokeSession(Request $request, string $session): RedirectResponse
    {
        if (config('session.driver') !== 'database') {
            throw new HttpException(409);
        }
        if ($session === $request->session()->getId()) {
            throw new HttpException(409);
        }
        DB::table('sessions')->where('id', $session)->where('user_id', $request->user()->id)->delete();
        $request
            ->user()
            ->forceFill(['remember_token' => Str::random(80)])
            ->save();
        Audit::record('account.session_revoked');

        return back()->with('status', __('site.account.privacy.session_removed'));
    }
}
