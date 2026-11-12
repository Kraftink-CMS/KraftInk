<?php

namespace App\Kraft\Migration;

use App\Models\Content\ContentComment;
use App\Models\Content\ContentEntry;
use App\Models\Imports\ImportCredential;
use App\Models\Accounts\GameIdentity;
use App\Models\Wallet\WalletAccount;
use App\Kraft\Auth\AccountIdentity;
use App\Kraft\ContentIcons;
use App\Kraft\Games\GameRegistry;
use App\Kraft\Games\Transports\TransportRegistry;
use App\Kraft\Html;
use App\Kraft\Wallet\Money;
use App\Kraft\Wallet\Wallet;
use Illuminate\Database\Connection;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use RuntimeException;

final class CoreMappings
{
    public function role(array $row, ImportContext $context): array
    {
        $color = $row['color'] ?? null;
        $id = $context->insert('roles', [
            'name' => $row['name'],
            'slug' => 'import-'.$context->adapter->id().'-'.$row['id'].'-'.substr($context->runId, 0, 8),
            'permissions' => '[]',
            'permission_profile' => 'none',
            'position' => max(0, min(998, (int) ($row['power'] ?? ($row['position'] ?? 0)))),
            'color' => is_string($color) && preg_match('/^#[a-fA-F0-9]{6}$/D', $color) ? $color : null,
            'text_color' => is_string($row['text_color'] ?? null) && preg_match('/^#[a-fA-F0-9]{6}$/D', $row['text_color'])
                    ? $row['text_color']
                    : null,
            'icon' => is_string($row['icon'] ?? null) && ContentIcons::validName($row['icon']) ? $row['icon'] : null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return ['target_table' => 'roles', 'target_id' => $id];
    }

    public function user(
        array $row,
        ImportContext $context,
        ?array $normalized = null,
        bool $persistIdentities = true,
    ): array {
        $user = $normalized ?? $context->adapter->user($row, $context);
        $email = strtolower(trim((string) $user['email']));
        $missingEmail = !filter_var($email, FILTER_VALIDATE_EMAIL);
        if ($missingEmail) {
            $email =
                'import-'.
                $context->adapter->id().
                '-'.
                $row['id'].
                '-'.
                substr($context->runId, 0, 8).
                '@accounts.invalid';
        }
        $matches = $this->accountMatches($user, $context->target, $email);
        if ($matches->count() > 1) {
            throw new RuntimeException('Ambiguous local account identity for source user '.$row['id']);
        }
        if ($matches->isNotEmpty()) {
            if (
                ImportCredential::on($context->target->getName())
                    ->where('run_id', $context->runId)
                    ->where('user_id', $matches->first())
                    ->exists()
            ) {
                throw new RuntimeException('Multiple source accounts cannot merge into one newly imported account.');
            }
            if (($context->options['existing_users'] ?? 'reject') !== 'preserve') {
                throw new RuntimeException('An account already exists in the destination. Source user: '.$row['id']);
            }

            return [
                'target_table' => 'users',
                'target_id' => $matches->first(),
                'metadata' => ['preserved_local' => true],
            ];
        }
        $native = preg_match('/^\$(2[aby]|argon2id|argon2i)\$/', (string) $user['password']) === 1;
        try {
            $balance = Money::parse((string) $user['balance']);
        } catch (ValidationException) {
            throw new RuntimeException(__('system.imports.invalid_balance', ['id' => $row['id']]));
        }
        $id = $context->insert('users', [
            'username' => AccountIdentity::availableUsername((string) $user['username'], $context->target),
            'email' => $email,
            'password' => $native ? $user['password'] : Hash::make(Str::random(80)),
            'local_login_enabled' => $native,
            'locale' => config('imports.locale', 'fr'),
            'email_verified_at' => $missingEmail ? null : $user['email_verified_at'],
            'force_password_reset' => !$native || $missingEmail,
            'import_locked' => true,
            'created_at' => $row['created_at'] ?? ($row['created'] ?? now()),
            'updated_at' => now(),
        ]);
        WalletAccount::on($context->target->getName())->create([
            'user_id' => $id,
            'balance_minor' => $balance,
            'currency' => app(Wallet::class)->currency(),
        ]);
        ImportCredential::on($context->target->getName())->create([
            'user_id' => $id,
            'run_id' => $context->runId,
            'source' => $context->adapter->id(),
            'payload' => [
                'password' => $user['password'],
                'scheme' => $row['password_hash'] ?? null,
                'two_factor_secret' => $row['two_factor_secret'] ?? null,
                'recovery_codes' => $row['two_factor_recovery_codes'] ?? null,
                'lock_required' => $user['locked'],
                'missing_email' => $missingEmail,
            ],
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        if ($persistIdentities) {
            foreach ($user['identities'] as $identity) {
                GameIdentity::on($context->target->getName())->create([
                    'user_id' => $id,
                    'game' => $identity['game'],
                    'uuid' => $identity['uuid'],
                    'username' => $identity['username'],
                    'verified_at' => null,
                    'metadata' => null,
                    'created_at' => $row['created_at'] ?? ($row['created'] ?? now()),
                    'updated_at' => now(),
                ]);
            }
        }
        $role = $context->mapped($context->adapter->roleTable(), $user['role']);
        if ($role !== null) {
            $context->target->table('role_user')->insert(['user_id' => $id, 'role_id' => $role]);
        }

        return ['target_table' => 'users', 'target_id' => $id];
    }

    public function accountMatches(array $user, Connection $target, ?string $email = null): Collection
    {
        $email ??= strtolower(trim((string) $user['email']));

        $matches = $target
            ->table('users')
            ->whereRaw('LOWER(email) = ?', [$email])
            ->pluck('id');
        if ($user['identities'] !== []) {
            $owners = $target
                ->table('game_identities')
                ->where(function ($query) use ($user): void {
                    foreach ($user['identities'] as $identity) {
                        $query->orWhere(
                            fn ($query) => $query->where('game', $identity['game'])->where('uuid', $identity['uuid']),
                        );
                    }
                })
                ->pluck('user_id');
            $matches = $matches->merge($owners);
        }

        return $matches->unique()->values();
    }

    public function lockUser(array $row, ImportContext $context): array
    {
        $id = $context->required($context->adapter->userTable(), $row['user_id']);
        $record = ImportCredential::on($context->target->getName())
            ->where('user_id', $id)
            ->where('run_id', $context->runId)
            ->first();
        if ($record === null) {
            return ['target_table' => 'users', 'target_id' => $id];
        }
        $payload = $record->payload;
        $payload['lock_required'] = true;
        $record->update(['payload' => $payload]);

        return ['target_table' => 'users', 'target_id' => $id];
    }

    public function content(array $row, ImportContext $context, string $kind, array $access): array
    {
        $title = (string) ($row['title'] ?? ($row['name'] ?? 'Imported content'));
        $sourceSlug = (string) ($row['slug'] ?? $title);
        $slug =
            $kind === 'page'
                ? implode(
                    '/',
                    array_filter(array_map(fn ($segment) => Str::slug($segment), explode('/', trim($sourceSlug, '/')))),
                )
                : Str::slug($sourceSlug);
        $slug = $slug ?: 'import-'.$row['id'];
        $locale = config('imports.locale', 'fr');
        if ($context->target->table('content_entries')->where(compact('kind', 'locale', 'slug'))->exists()) {
            $slug .= '-import-'.$row['id'].'-'.substr($context->runId, 0, 8);
        }
        $id = $context->insert('content_entries', [
            'kind' => $kind,
            'locale' => $locale,
            'slug' => $slug,
            'title' => $title,
            'body' => ImportedHtml::clean($row['content'] ?? ''),
            'summary' => strip_tags($row['description'] ?? ''),
            'is_pinned' => $kind === 'news' && !empty($row['is_pinned']),
            'visibility' => $access['visibility'],
            'roles_restricted' => count($access['role_ids']) > 0,
            'required_permission' => $access['required_permission'],
            'author_id' => $context->mapped(
                $context->adapter->userTable(),
                $row['author_id'] ?? ($row['user_id'] ?? null),
            ),
            'published_at' => null,
            'created_at' => $row['created_at'] ?? ($row['created'] ?? now()),
            'updated_at' => now(),
        ]);
        foreach ($access['role_ids'] as $role) {
            $context->target
                ->table('content_entry_role')
                ->insertOrIgnore(['content_entry_id' => $id, 'role_id' => $role]);
        }

        return ['target_table' => 'content_entries', 'target_id' => $id];
    }

    public function comment(array $row, ImportContext $context): array
    {
        $entry = $context->required($context->adapter->newsTable(), $row['post_id'] ?? $row['news_id']);
        $author = $context->required($context->adapter->userTable(), $row['author_id'] ?? $row['user_id']);
        $id = $context->insert('content_comments', [
            'content_entry_id' => $entry,
            'user_id' => $author,
            'body' => Html::clean($row['content'] ?? ''),
            'state' => 'pending',
            'created_at' => $row['created_at'] ?? ($row['created'] ?? now()),
            'updated_at' => $row['updated_at'] ?? ($row['updated'] ?? now()),
        ]);

        return ['target_table' => 'content_comments', 'target_id' => $id];
    }

    public function reaction(array $row, ImportContext $context): array
    {
        $entry = $context->required($context->adapter->newsTable(), $row['post_id'] ?? $row['news_id']);
        $author = $context->required($context->adapter->userTable(), $row['author_id'] ?? $row['user_id']);
        $context->target->table('content_reactions')->insertOrIgnore([
            'content_entry_id' => $entry,
            'user_id' => $author,
            'created_at' => $row['created_at'] ?? ($row['created'] ?? now()),
        ]);

        return [
            'target_table' => 'content_reactions',
            'target_id' => $context->target
                ->table('content_reactions')
                ->where('content_entry_id', $entry)
                ->where('user_id', $author)
                ->value('id'),
        ];
    }

    public function contentReplacements(string $kind): array
    {
        $scope = ['kind' => [$kind], 'locale' => [config('imports.locale')]];
        $entries = ContentEntry::withTrashed()
            ->where('kind', $kind)
            ->where('locale', config('imports.locale'))
            ->pluck('id')
            ->all();
        $comments = ContentComment::withTrashed()->whereIn('content_entry_id', $entries)->pluck('id')->all();

        return [
            'content_comment_revisions' => ['content_comment_id' => $comments],
            'content_comments' => ['content_entry_id' => $entries],
            'content_reactions' => ['content_entry_id' => $entries],
            'content_attachments' => ['content_entry_id' => $entries],
            'content_publications' => ['content_entry_id' => $entries],
            'content_revisions' => ['content_entry_id' => $entries],
            'content_entry_image' => ['content_entry_id' => $entries],
            'content_entry_role' => ['content_entry_id' => $entries],
            'content_entries' => $scope,
        ];
    }

    public function server(array $row, ImportContext $context, ?string $game = null): array
    {
        $games = app(GameRegistry::class);
        $game ??= $context->options['game'] ?? $games->activeId();
        $transports = $game ? app(TransportRegistry::class)->metadata($game) : [];
        $connections = array_values(
            array_filter(
                array_keys($games->all()[$game]['connections'] ?? []),
                fn (string $connection) => $connection === 'manual' ||
                    (isset($transports[$connection]) && empty($transports[$connection]['secret'])),
            ),
        );
        $connection = $row['connection'] ?? null;
        if (!in_array($connection, $connections, true)) {
            $connection = in_array('manual', $connections, true) ? 'manual' : $connections[0] ?? null;
        }
        if ($connection === null) {
            throw new RuntimeException('The source server has no compatible connection mode.');
        }
        $id = $context->insert('servers', [
            'name' => $row['name'],
            'game' => $game,
            'host' => $row['address'] ?? ($row['ip'] ?? '127.0.0.1'),
            'port' => (int) ($row['port'] ?? 25565),
            'show_port' => (bool) ($row['show_port'] ?? true),
            'show_players' => (bool) ($row['show_players'] ?? true),
            'connection' => $connection,
            'enabled' => false,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return ['target_table' => 'servers', 'target_id' => $id];
    }
}
