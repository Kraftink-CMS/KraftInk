<?php

namespace App\Http\Controllers\Account;

use Symfony\Component\HttpKernel\Exception\HttpException;
use App\Http\Controllers\Controller;
use App\Kraft\Site\ViewCapabilities;
use App\Kraft\OAuth\Discord\RoleConnections;
use App\Kraft\OAuth\Discord\RoleMetadata;
use App\Kraft\Settings;
use App\Kraft\System\SensitiveAction;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Throwable;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use JsonException;
use Illuminate\Contracts\Cache\LockTimeoutException;

class DiscordRoleController extends Controller
{
    public function index(Request $request, RoleConnections $connections, RoleMetadata $metadata): View
    {
        $user = $request->user();
        try {
            $preview = $connections->ready() ? $metadata->values($user) : [];
        } catch (Throwable) {
            $preview = [];
        }

        return view('default.user.account.discord.index', [
            'ready' => $connections->ready() && $preview !== [],
            'preview' => $preview,
            'criteria' => $metadata->all(),
            'selected' => $this->selected($metadata),
            'connections' => $user
                ->discordRoleConnections()
                ->orderByDesc('id')
                ->paginate(
                    25,
                    [
                        'id',
                        'provider',
                        'application_id',
                        'consented_at',
                        'last_synced_at',
                        'next_sync_at',
                        'last_error',
                    ],
                    'connections_page',
                )
                ->withQueryString(),
            'revocations' => $user
                ->discordRoleRevocations()
                ->where('state', '!=', 'completed')
                ->latest()
                ->orderByDesc('id')
                ->paginate(25, ['id', 'provider', 'state', 'last_error', 'reason', 'created_at'], 'revocations_page')
                ->withQueryString(),
        ]);
    }

    /**
     * @throws JsonException
     */
    public function start(Request $request, RoleConnections $connections): RedirectResponse
    {
        if (!$connections->ready()) {
            throw new HttpException(403);
        }
        $request->validate(['consent' => 'accepted']);

        app(SensitiveAction::class)->confirm($request);
        $session = $request->session();
        $previous = $session->only(['state', 'code_verifier']);
        try {
            $response = $connections->driver($connections->provider())->redirect();
            /** @var Settings $settings */
            $settings = app(Settings::class);
            $session->put('discord.roles.intent', [
                'user_id' => $request->user()->id,
                'version' => $request->user()->auth_session_version,
                'provider' => $connections->provider(),
                'definition' => $connections->definitionHash(),
                'revision' => (int) $settings->get('oauth.revision', 0),
                'at' => time(),
                'state' => $session->pull('state'),
                'verifier' => $session->pull('code_verifier'),
            ]);
        } finally {
            $session->forget(['state', 'code_verifier']);
            $session->put($previous);
        }

        return $response;
    }

    /**
     * @throws JsonException
     * @throws LockTimeoutException
     */
    public function callback(Request $request, RoleConnections $connections): RedirectResponse
    {
        $session = $request->session();
        $intent = $session->pull('discord.roles.intent');
        if (
            !(
                is_array($intent) &&
                $intent['at'] > time() - 300 &&
                $intent['user_id'] === $request->user()->id &&
                (int) $intent['version'] === (int) $request->user()->auth_session_version &&
                is_string($intent['state'] ?? null) &&
                is_string($request->input('state')) &&
                hash_equals($intent['state'], $request->input('state'))
            )
        ) {
            throw new HttpException(403);
        }
        /** @var Settings $settings */
        $settings = app(Settings::class);
        if (
            !(
                $connections->ready() &&
                $intent['provider'] === $connections->provider() &&
                hash_equals($intent['definition'], $connections->definitionHash()) &&
                (int) $intent['revision'] === (int) $settings->get('oauth.revision', 0)
            )
        ) {
            throw new HttpException(403);
        }
        if ($request->has('error')) {
            throw ValidationException::withMessages([
                'discord_roles' => __('admin.integrations.discord.authorization_missing'),
            ]);
        }
        $previous = $session->only(['state', 'code_verifier']);
        try {
            $session->put(['state' => $intent['state'], 'code_verifier' => $intent['verifier']]);
            $identity = $connections->driver($intent['provider'])->user();
        } catch (Throwable) {
            throw ValidationException::withMessages([
                'discord_roles' => __('admin.integrations.discord.authorization_missing'),
            ]);
        } finally {
            $session->forget(['state', 'code_verifier']);
            $session->put($previous);
        }
        $connection = $connections->accept($request->user(), $intent['provider'], $identity);
        $synced = $connections->sync($connection->id);

        return redirect()
            ->route('account.discord.roles.index')
            ->with(
                'status',
                __($synced ? 'admin.integrations.discord.linked' : 'admin.integrations.discord.publication_pending'),
            );
    }

    public function destroy(Request $request, int $connection, RoleConnections $connections): RedirectResponse
    {
        app(SensitiveAction::class)->confirm($request);
        $record = $request->user()->discordRoleConnections()->findOrFail($connection);
        $revocation = $connections->revoke($record, 'user_requested');
        $removed = $revocation && $connections->remove($revocation->id);

        return redirect()
            ->route(
                ViewCapabilities::forRequest($request, withExtensions: false)->canManageDiscordRoles
                    ? 'account.discord.roles.index'
                    : 'account.index',
            )
            ->with(
                'status',
                __($removed ? 'admin.integrations.discord.removed' : 'admin.integrations.discord.removal_pending'),
            );
    }

    private function selected(RoleMetadata $metadata): array
    {
        try {
            return $metadata->selected();
        } catch (Throwable) {
            return [];
        }
    }
}
