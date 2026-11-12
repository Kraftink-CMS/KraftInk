<?php

namespace App\Kraft\Migration\Adapters\KraftInk;

use App\Models\Imports\ImportCredential;
use App\Models\Wallet\WalletAccount;
use App\Kraft\ContentIcons;
use App\Kraft\Games\GameRegistry;
use App\Kraft\Migration\CoreMappings as SharedCoreMappings;
use App\Kraft\Migration\ImportContext;
use App\Kraft\Migration\ImportedHtml;
use App\Kraft\NavigationTargets;
use App\Kraft\Redirects;
use App\Kraft\Wallet\Money;
use App\Kraft\Wallet\Wallet;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;
use WeakMap;

final class CoreMappings
{
    private ?WeakMap $navigation = null;

    private ?WeakMap $settings = null;

    public function __construct(private readonly SharedCoreMappings $core)
    {
    }

    public function user(array $row, ImportContext $context): array
    {
        $result = $this->core->user($row, $context, persistIdentities: false);
        $preserved = !ImportCredential::on($context->target->getName())
            ->where('run_id', $context->runId)
            ->where('user_id', $result['target_id'])
            ->exists();
        $result['metadata'] = ['preserved_local' => $preserved];
        if ($preserved) {
            return $result;
        }
        $local = (bool) ($row['local_login_enabled'] ?? true);
        $current = $context->target
            ->table('users')
            ->where('id', $result['target_id'])
            ->first(['local_login_enabled', 'force_password_reset']);
        if (
            WalletAccount::on($context->target->getName())->findOrFail($result['target_id'])->balance_minor > 0 &&
            $this->currency($context) !== app(Wallet::class)->currency()
        ) {
            throw new RuntimeException('The archived source wallet currency does not match the destination.');
        }
        $context->target
            ->table('users')
            ->where('id', $result['target_id'])
            ->update([
                'locale' => $this->locale($row['locale'] ?? null),
                'appearance' => in_array($row['appearance'] ?? null, ['light', 'dark', 'system'], true)
                    ? $row['appearance']
                    : null,
                'local_login_enabled' => $local && (bool) $current->local_login_enabled && empty($row['anonymized_at']),
                'force_password_reset' => !$local ||
                    !empty($row['force_password_reset']) ||
                    !empty($row['oauth_recovery_required_at']) ||
                    (bool) $current->force_password_reset,
                'banned_permanently' => !empty($row['banned_permanently']),
                'banned_until' => $row['banned_until'] ?? null,
                'anonymized_at' => $row['anonymized_at'] ?? null,
            ]);

        return $result;
    }

    public function roleUser(array $row, ImportContext $context): array
    {
        $user = $context->required('users', $row['user_id']);
        if ($this->newAccount($context, $user)) {
            $context->target
                ->table('role_user')
                ->insertOrIgnore(['user_id' => $user, 'role_id' => $context->required('roles', $row['role_id'])]);
        }

        return ['target_table' => 'users', 'target_id' => $user];
    }

    public function ban(array $row, ImportContext $context): array
    {
        $user = $context->required('users', $row['user_id']);
        if (!$this->newAccount($context, $user)) {
            return ['target_table' => null, 'target_id' => null];
        }

        return [
            'target_table' => 'user_bans',
            'target_id' => $context->insert('user_bans', [
                'user_id' => $user,
                'author_id' => $context->mapped('users', $row['author_id'] ?? null),
                'reason' => $row['reason'],
                'expires_at' => $row['expires_at'] ?? null,
                'revoked_at' => $row['revoked_at'] ?? null,
                'revoked_by' => $context->mapped('users', $row['revoked_by'] ?? null),
                'created_at' => $row['created_at'] ?? now(),
                'updated_at' => $row['updated_at'] ?? now(),
            ]),
        ];
    }

    public function identity(array $row, ImportContext $context): array
    {
        $user = $context->required('users', $row['user_id']);
        if (!$this->newAccount($context, $user)) {
            return ['target_table' => null, 'target_id' => null];
        }
        $adapter = app(GameRegistry::class)->find($row['game']);
        if ($adapter === null) {
            throw new RuntimeException('The source identity uses an unavailable game.');
        }

        return [
            'target_table' => 'game_identities',
            'target_id' => $context->insert('game_identities', [
                'user_id' => $user,
                'game' => $row['game'],
                'uuid' => $adapter->normalizeUuid($row['uuid']),
                'username' => $row['username'],
                'verified_at' => null,
                'metadata' => null,
                'created_at' => $row['created_at'] ?? now(),
                'updated_at' => $row['updated_at'] ?? now(),
            ]),
        ];
    }

    public function wallet(array $row, ImportContext $context): array
    {
        $user = $context->required('users', $row['user_id']);
        if ($this->newAccount($context, $user)) {
            $account = WalletAccount::on($context->target->getName())->findOrFail($user);
            if (
                !ctype_digit((string) $row['balance_minor']) ||
                $row['balance_minor'] > Money::MaxBalance ||
                $account->balance_minor !== (int) $row['balance_minor'] ||
                ((int) $row['balance_minor'] > 0 && $row['currency'] !== app(Wallet::class)->currency())
            ) {
                throw new RuntimeException('The source wallet balance or currency does not match the account.');
            }
            $account->update([
                'created_at' => $row['created_at'] ?? now(),
                'updated_at' => $row['updated_at'] ?? now(),
            ]);
        }

        return ['target_table' => 'users', 'target_id' => $user];
    }

    public function content(array $row, ImportContext $context): array
    {
        if (!in_array($row['kind'], ['page', 'news'], true)) {
            throw new RuntimeException('Unsupported native content kind.');
        }
        $slug =
            $row['kind'] === 'page'
                ? implode('/', array_filter(array_map(Str::slug(...), explode('/', trim($row['slug'], '/')))))
                : Str::slug($row['slug']);
        $slug = $slug ?: 'import-'.$row['id'];
        $locale = $this->locale($row['locale']);
        if (
            $context->target
                ->table('content_entries')
                ->where(['kind' => $row['kind'], 'locale' => $locale, 'slug' => $slug])
                ->exists()
        ) {
            $slug .= '-import-'.$row['id'].'-'.substr($context->runId, 0, 8);
        }
        $visibility = in_array($row['visibility'] ?? 'public', ['public', 'auth', 'restricted'], true)
            ? $row['visibility'] ?? 'public'
            : 'restricted';
        $id = $context->insert('content_entries', [
            'kind' => $row['kind'],
            'locale' => $locale,
            'slug' => $slug,
            'title' => $row['title'],
            'body' => ImportedHtml::clean($row['body']),
            'summary' => strip_tags($row['summary'] ?? ''),
            'author_id' => $context->mapped('users', $row['author_id'] ?? null),
            'published_at' => null,
            'is_pinned' => !empty($row['is_pinned']),
            'comments_enabled' => !empty($row['comments_enabled']),
            'reactions_enabled' => !empty($row['reactions_enabled']),
            'comments_moderated' => !empty($row['comments_moderated']),
            'visibility' => $visibility,
            'roles_restricted' => !empty($row['roles_restricted']),
            'required_permission' => $row['required_permission'] ??
                ($visibility === 'restricted' && empty($row['roles_restricted']) ? 'imports.review' : null),
            'deleted_at' => $row['deleted_at'] ?? null,
            'created_at' => $row['created_at'] ?? now(),
            'updated_at' => now(),
        ]);

        return ['target_table' => 'content_entries', 'target_id' => $id];
    }

    public function contentRole(array $row, ImportContext $context): array
    {
        $entry = $context->required('content_entries', $row['content_entry_id']);
        $context->target
            ->table('content_entry_role')
            ->insertOrIgnore(['content_entry_id' => $entry, 'role_id' => $context->required('roles', $row['role_id'])]);
        $context->target
            ->table('content_entries')
            ->where('id', $entry)
            ->update(['roles_restricted' => true, 'visibility' => 'restricted']);

        return ['target_table' => 'content_entries', 'target_id' => $entry];
    }

    public function comment(array $row, ImportContext $context): array
    {
        return [
            'target_table' => 'content_comments',
            'target_id' => $context->insert('content_comments', [
                'content_entry_id' => $context->required('content_entries', $row['content_entry_id']),
                'user_id' => $context->mapped('users', $row['user_id'] ?? null),
                'body' => ImportedHtml::clean($row['body']),
                'state' => 'pending',
                'deleted_at' => $row['deleted_at'] ?? null,
                'created_at' => $row['created_at'] ?? now(),
                'updated_at' => $row['updated_at'] ?? now(),
            ]),
        ];
    }

    public function reaction(array $row, ImportContext $context): array
    {
        return [
            'target_table' => 'content_reactions',
            'target_id' => $context->insert('content_reactions', [
                'content_entry_id' => $context->required('content_entries', $row['content_entry_id']),
                'user_id' => $context->required('users', $row['user_id']),
                'created_at' => $row['created_at'] ?? now(),
            ]),
        ];
    }

    public function commentRevision(array $row, ImportContext $context): array
    {
        return [
            'target_table' => 'content_comment_revisions',
            'target_id' => $context->insert('content_comment_revisions', [
                'content_comment_id' => $context->required('content_comments', $row['content_comment_id']),
                'actor_id' => $context->mapped('users', $row['actor_id'] ?? null),
                'action' => $row['action'],
                'body' => ImportedHtml::clean($row['body']),
                'state' => 'pending',
                'created_at' => $row['created_at'] ?? now(),
            ]),
        ];
    }

    public function server(array $row, ImportContext $context): array
    {
        $row['address'] = $row['host'];
        $game = array_key_exists($row['game'], app(GameRegistry::class)->all()) ? $row['game'] : null;
        $result = $this->core->server($row, $context, $game);
        if ((string) ($this->sourceSettings($context)['servers.primary'] ?? '') === (string) $row['id']) {
            $context->setting('servers.primary', $result['target_id']);
        }

        return $result;
    }

    public function navigation(array $row, ImportContext $context): array
    {
        $this->navigation ??= new WeakMap();
        if (!isset($this->navigation[$context])) {
            $rows = [];
            foreach ($context->sourceRows('navigation_links') ?? [] as $link) {
                $rows[(string) $link['id']] = $link;
            }
            $this->navigation[$context] = ['rows' => $rows, 'visiting' => []];
        }
        $state = $this->navigation[$context];
        $key = (string) $row['id'];
        if (isset($state['visiting'][$key])) {
            throw new RuntimeException('The source navigation contains a parent cycle.');
        }
        $state['visiting'][$key] = true;
        $this->navigation[$context] = $state;
        try {
            $parent = $row['parent_id'] ?? null;
            if ($parent !== null && $context->mapped('navigation_links', $parent) === null) {
                $source =
                    $state['rows'][(string) $parent] ?? throw new RuntimeException('Missing source navigation parent.');
                $context->remember('navigation_links', $source, $this->navigation($source, $context));
            }
            $labels = json_decode($row['labels'], true, flags: JSON_THROW_ON_ERROR);
            if (!is_array($labels) || array_filter($labels, fn ($label) => !is_string($label))) {
                throw new RuntimeException('Invalid source navigation labels.');
            }
            $type = $row['destination_type'] ?? 'url';
            $route =
                is_string($row['route_name'] ?? null) &&
                array_key_exists($row['route_name'], app(NavigationTargets::class)->routes())
                    ? $row['route_name']
                    : null;
            $type = $type === 'content' ? 'content' : ($type === 'route' && $route !== null ? 'route' : 'url');
            $id = $context->insert('navigation_links', [
                'parent_id' => $parent === null ? null : $context->required('navigation_links', $parent),
                'location' => in_array($row['location'], config('community.navigation_locations'), true)
                    ? $row['location']
                    : 'header',
                'labels' => json_encode($labels, JSON_THROW_ON_ERROR),
                'url' => NavigationTargets::validUrl($row['url']) ? $row['url'] : '/',
                'destination_type' => $type,
                'content_id' => $type === 'content' ? $context->required('content_entries', $row['content_id']) : null,
                'route_name' => $type === 'route' ? $route : null,
                'visibility' => in_array($row['visibility'], ['all', 'guest', 'auth'], true)
                    ? $row['visibility']
                    : 'auth',
                'permission' => $row['permission'] ?? null,
                'roles_restricted' => !empty($row['roles_restricted']),
                'icon' => ContentIcons::validName($row['icon'] ?? '') ? $row['icon'] : null,
                'position' => max(0, (int) $row['position']),
                'enabled' => false,
                'new_tab' => !empty($row['new_tab']),
                'created_at' => $row['created_at'] ?? now(),
                'updated_at' => $row['updated_at'] ?? now(),
            ]);

            return ['target_table' => 'navigation_links', 'target_id' => $id];
        } finally {
            $state = $this->navigation[$context];
            unset($state['visiting'][$key]);
            $this->navigation[$context] = $state;
        }
    }

    public function navigationRole(array $row, ImportContext $context): array
    {
        $link = $context->required('navigation_links', $row['navigation_link_id']);
        $context->target->table('navigation_link_role')->insertOrIgnore([
            'navigation_link_id' => $link,
            'role_id' => $context->required('roles', $row['role_id']),
        ]);
        $context->target
            ->table('navigation_links')
            ->where('id', $link)
            ->update(['roles_restricted' => true]);

        return ['target_table' => 'navigation_links', 'target_id' => $link];
    }

    public function redirect(array $row, ImportContext $context): array
    {
        $redirects = app(Redirects::class);
        $source = $redirects->source($row['source']);
        if ($source === null || !$redirects->validDestination($row['destination'])) {
            throw new RuntimeException('Invalid source redirection.');
        }
        if ($context->target->table('site_redirects')->where('source', $source)->exists()) {
            return ['target_table' => null, 'target_id' => null, 'metadata' => ['preserved_local' => true]];
        }

        return [
            'target_table' => 'site_redirects',
            'target_id' => $context->insert('site_redirects', [
                'source' => $source,
                'destination' => $row['destination'],
                'status' => in_array((int) $row['status'], [301, 302, 307, 308], true) ? $row['status'] : 301,
                'enabled' => false,
                'automatic' => false,
                'created_at' => $row['created_at'] ?? now(),
                'updated_at' => $row['updated_at'] ?? now(),
            ]),
        ];
    }

    public function contentReplacements(): array
    {
        $ids = DB::table('content_entries')
            ->whereIn('kind', ['page', 'news'])
            ->pluck('id')
            ->all();
        $comments = DB::table('content_comments')->whereIn('content_entry_id', $ids)->pluck('id')->all();

        return [
            'content_comment_revisions' => ['content_comment_id' => $comments],
            'content_comments' => ['content_entry_id' => $ids],
            'content_reactions' => ['content_entry_id' => $ids],
            'content_attachments' => ['content_entry_id' => $ids],
            'content_publications' => ['content_entry_id' => $ids],
            'content_revisions' => ['content_entry_id' => $ids],
            'content_entry_image' => ['content_entry_id' => $ids],
            'content_entry_role' => ['content_entry_id' => $ids],
            'content_entries' => ['kind' => ['page', 'news']],
        ];
    }

    private function newAccount(ImportContext $context, int $user): bool
    {
        return ImportCredential::on($context->target->getName())
            ->where('user_id', $user)
            ->where('run_id', $context->runId)
            ->exists();
    }

    private function currency(ImportContext $context): ?string
    {
        $currency = $this->sourceSettings($context)['wallet.currency'] ?? null;

        return is_string($currency) && $currency !== '' ? $currency : null;
    }

    private function sourceSettings(ImportContext $context): array
    {
        $this->settings ??= new WeakMap();
        if (!isset($this->settings[$context])) {
            $settings = [];
            foreach ($context->sourceRows('settings') ?? [] as $setting) {
                $settings[$setting['key']] = json_decode($setting['value'], true);
            }
            $this->settings[$context] = $settings;
        }

        return $this->settings[$context];
    }

    private function locale(mixed $locale): string
    {
        return is_string($locale) && preg_match('/^[a-z]{2}(?:[-_][A-Za-z]{2,8})?$/D', $locale) && strlen($locale) <= 12
            ? $locale
            : config('imports.locale', 'fr');
    }
}
