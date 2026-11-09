<?php

namespace App\Http\Controllers\Admin\Settings;

use App\Kraft\System\TableQuery;
use App\Http\Controllers\Controller;
use App\Kraft\Audit;
use App\Kraft\OAuth\OAuthRegistry;
use App\Kraft\OAuth\Discord\DiscordRoleApi;
use App\Models\OAuth\Discord\RoleConnection;
use App\Kraft\OAuth\Discord\RoleConnections;
use App\Kraft\OAuth\Discord\RoleMetadata;
use App\Models\OAuth\Discord\RoleRevocation;
use App\Kraft\OAuth\Discord\RoleTokens;
use App\Kraft\Secrets;
use App\Kraft\Settings;
use App\Kraft\System\SensitiveAction;
use App\Models\Accounts\Role;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Throwable;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use JsonException;

class DiscordRoleSettingsController extends Controller
{
    public function index(
        RoleConnections $connections,
        RoleMetadata $metadata,
        OAuthRegistry $registry,
        Secrets $secrets,
        Settings $settings,
    ): View {
        $providers = array_filter(
            $registry->definitions(),
            fn ($provider) => ($provider['adapter'] ?? null) === 'discord',
        );
        $enabledProviders = $registry->enabled();
        $hasToken = $secrets->get('discord.roles.bot_token') !== '';
        try {
            $published = hash_equals(
                $connections->definitionHash(),
                (string) $settings->get('discord.roles.registered_hash', ''),
            );
        } catch (Throwable) {
            $published = false;
        }

        return view('admin.settings.discord.index', [
            'settings' => $settings,
            'ready' => $connections->ready(),
            'criteria' => $metadata->all(),
            'providers' => $providers,
            'enabledProviders' => $enabledProviders,
            'hasActiveApplication' => array_intersect(array_keys($providers), $enabledProviders) !== [],
            'registry' => $registry,
            'hasToken' => $hasToken,
            'canPublish' => $hasToken && in_array($connections->provider(), $enabledProviders, true),
            'published' => $published,
            'roles' => TableQuery::paginate(
                Role::query()
                    ->select(['id', 'name', 'position'])
                    ->orderByDesc('position')
                    ->orderBy('id'),
                ['name', 'id', 'position'],
                pageName: 'roles_page',
                search: ['name'],
            ),
            'connectionCount' => RoleConnection::query()->count(),
            'revocations' => TableQuery::paginate(
                RoleRevocation::query()
                    ->leftJoin('users', 'users.id', '=', 'discord_role_revocations.user_id')
                    ->latest('discord_role_revocations.created_at')
                    ->select([
                        'discord_role_revocations.id',
                        'discord_role_revocations.provider',
                        'discord_role_revocations.state',
                        'discord_role_revocations.reason',
                        'discord_role_revocations.last_error',
                        'discord_role_revocations.created_at',
                        'discord_role_revocations.completed_at',
                        'users.username as user_username',
                    ]),
                [
                    'username' => 'users.username',
                    'reason' => 'discord_role_revocations.reason',
                    'state' => 'discord_role_revocations.state',
                    'created_at' => 'discord_role_revocations.created_at',
                ],
            ),
        ]);
    }

    public function store(
        Request $request,
        RoleMetadata $metadata,
        OAuthRegistry $registry,
        Settings $settings,
        Secrets $secrets,
    ): RedirectResponse {
        app(SensitiveAction::class)->confirm($request);
        $providers = array_keys(
            array_filter($registry->definitions(), fn ($provider) => ($provider['adapter'] ?? null) === 'discord'),
        );
        if (!$request->boolean('enabled') && !$request->filled('fields')) {
            $request->merge(['fields' => ['role_id', 'role_power']]);
        }
        $data = $request->validate([
            'enabled' => 'required|boolean',
            'provider' => ['nullable', Rule::in($providers)],
            'fields' => 'required|array|min:1|max:5',
            'fields.*' => ['required', 'string', 'distinct', Rule::in(array_keys($metadata->all()))],
            'bot_token' => 'nullable|string|max:8192|regex:/^[^\s\x00-\x1F\x7F]+$/D',
        ]);
        if (
            $request->boolean('enabled') &&
            (empty($data['provider']) ||
                !in_array($data['provider'], $registry->enabled(), true) ||
                !preg_match(
                    '/^[0-9]{5,32}$/D',
                    (string) $settings->get('oauth.'.$data['provider'].'.client_id', ''),
                ) ||
                (($data['bot_token'] ?? '') === '' && $secrets->get('discord.roles.bot_token') === ''))
        ) {
            throw ValidationException::withMessages([
                'provider' => __('admin.integrations.discord.configuration_required'),
            ]);
        }
        DB::transaction(function () use ($settings, $secrets, $data, $request) {
            $settings->set('discord.roles.enabled', $request->boolean('enabled'));
            $settings->set('discord.roles.provider', $data['provider'] ?? null);
            $settings->set('discord.roles.fields', array_values($data['fields']));
            if (!empty($data['bot_token'])) {
                $secrets->set('discord.roles.bot_token', $data['bot_token']);
            }
        });
        Audit::record('discord.roles.settings', [
            'enabled' => $request->boolean('enabled'),
            'provider' => $data['provider'] ?? null,
        ]);

        return redirect()
            ->route('admin.settings.discord.index')
            ->with('status', __('admin.integrations.discord.saved'));
    }

    /**
     * @throws JsonException
     */
    public function publish(
        Request $request,
        RoleConnections $connections,
        RoleMetadata $metadata,
        OAuthRegistry $registry,
        Secrets $secrets,
        Settings $settings,
    ): RedirectResponse {
        app(SensitiveAction::class)->confirm($request);
        $provider = $connections->provider();
        if (
            !$provider ||
            !in_array($provider, $registry->enabled(), true) ||
            $secrets->get('discord.roles.bot_token') === ''
        ) {
            throw ValidationException::withMessages([
                'provider' => __('admin.integrations.discord.configuration_required'),
            ]);
        }
        try {
            $response = app(DiscordRoleApi::class)->register(
                (string) $settings->get('oauth.'.$provider.'.client_id'),
                $secrets->get('discord.roles.bot_token'),
                $metadata->records(),
            );
            app(RoleTokens::class)->ensure($response);
        } catch (Throwable) {
            throw ValidationException::withMessages([
                'discord_roles' => __('admin.integrations.discord.publish_failed'),
            ]);
        }
        $settings->set('discord.roles.registered_hash', $connections->definitionHash());
        Audit::record('discord.roles.metadata_published', ['provider' => $provider, 'fields' => $metadata->selected()]);

        return redirect()
            ->route('admin.settings.discord.index')
            ->with('status', __('admin.integrations.discord.published'));
    }

    public function retry(Request $request, RoleConnections $connections): RedirectResponse
    {
        app(SensitiveAction::class)->confirm($request);
        RoleRevocation::query()
            ->where('state', 'pending')
            ->update(['next_attempt_at' => now()]);
        $connections->due();

        return redirect()
            ->route('admin.settings.discord.index')
            ->with('status', __('admin.integrations.discord.retry_queued'));
    }
}
