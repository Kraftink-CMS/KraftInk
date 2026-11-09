<?php

namespace App\Kraft\Migration\Adapters\KraftInk;

use App\Kraft\Migration\AbstractSourceAdapter;
use App\Kraft\Migration\Contracts\ValidatesSource;
use App\Kraft\Migration\CoreMappings as SharedCoreMappings;
use App\Kraft\Migration\MappingRegistry;
use App\Kraft\Migration\ImportContext;
use App\Kraft\Wallet\Money;
use Illuminate\Database\Connection;
use Illuminate\Support\Carbon;
use RuntimeException;
use WeakMap;

final class Adapter extends AbstractSourceAdapter implements ValidatesSource
{
    private ?WeakMap $identities = null;

    private ?WeakMap $wallets = null;

    public function __construct(
        private readonly CoreMappings $mappings,
        private readonly SourceValidation $validation,
    ) {
    }

    public function id(): string
    {
        return 'kraftink';
    }

    public function requirements(): array
    {
        return [
            'users' => ['id', 'username', 'email', 'password', 'account_type'],
            'wallet_accounts' => ['user_id', 'balance_minor', 'currency'],
            'settings' => ['key', 'value'],
            'roles' => ['id', 'name', 'slug'],
            'role_user' => ['user_id', 'role_id'],
            'content_entries' => ['id', 'kind', 'locale', 'slug', 'title', 'body'],
        ];
    }

    public function roleTable(): string
    {
        return 'roles';
    }

    public function newsTable(): string
    {
        return 'content_entries';
    }

    public function user(array $row, ImportContext $context): array
    {
        if ($row['account_type'] !== 'human') {
            throw new RuntimeException(__('service_accounts.import_not_supported'));
        }

        return [
            'username' => $row['username'],
            'email' => $row['email'],
            'password' => $row['password'],
            'identities' => $this->identities($row['id'], $context),
            'balance' => Money::decimal((int) ($this->wallets($context)[(string) $row['id']]['balance_minor'] ?? 0)),
            'role' => null,
            'email_verified_at' => $row['email_verified_at'] ?? null,
            'locked' => !empty($row['two_factor_secret']) ||
                !empty($row['two_factor_confirmed_at']) ||
                !empty($row['import_locked']) ||
                !empty($row['banned_permanently']) ||
                !empty($row['anonymized_at']) ||
                (!empty($row['banned_until']) && Carbon::parse($row['banned_until'], 'UTC')->isFuture()),
        ];
    }

    public function validateSource(Connection $source, array $options): array
    {
        return $this->validation->validate($source, $options, $this);
    }

    private function wallets(ImportContext $context): array
    {
        $this->wallets ??= new WeakMap();
        if (!isset($this->wallets[$context])) {
            $wallets = [];
            foreach ($context->sourceRows('wallet_accounts') ?? [] as $wallet) {
                $wallets[(string) $wallet['user_id']] = $wallet;
            }
            $this->wallets[$context] = $wallets;
        }

        return $this->wallets[$context];
    }

    private function identities(int|string $user, ImportContext $context): array
    {
        $this->identities ??= new WeakMap();
        if (!isset($this->identities[$context])) {
            $identities = [];
            foreach ($context->sourceRows('game_identities') ?? [] as $identity) {
                $identities[(string) $identity['user_id']][] = [
                    'game' => $identity['game'],
                    'uuid' => $identity['uuid'],
                    'username' => $identity['username'],
                ];
            }
            foreach ($identities as $id => $entries) {
                $identities[$id] = $this->normalizeIdentities($entries);
            }
            $this->identities[$context] = $identities;
        }

        return $this->identities[$context][(string) $user] ?? [];
    }

    public function registerMappings(MappingRegistry $registry, SharedCoreMappings $core): void
    {
        $registry->register($this->id(), 'roles', $core->role(...), 10);
        $registry->register($this->id(), 'users', $this->mappings->user(...), 20);
        $registry->register(
            $this->id(),
            'role_user',
            $this->mappings->roleUser(...),
            25,
            dependencies: ['roles', 'users'],
        );
        $registry->register($this->id(), 'user_bans', $this->mappings->ban(...), 26, dependencies: ['users']);
        $registry->register(
            $this->id(),
            'game_identities',
            $this->mappings->identity(...),
            26,
            dependencies: ['users'],
        );
        $registry->register(
            $this->id(),
            'wallet_accounts',
            $this->mappings->wallet(...),
            27,
            identity: 'user_id',
            dependencies: ['users'],
        );
        $registry->register(
            $this->id(),
            'content_entries',
            $this->mappings->content(...),
            30,
            replacements: $this->mappings->contentReplacements(...),
            dependencies: ['users'],
        );
        $registry->register(
            $this->id(),
            'content_entry_role',
            $this->mappings->contentRole(...),
            31,
            dependencies: ['content_entries', 'roles'],
        );
        $registry->register(
            $this->id(),
            'content_comments',
            $this->mappings->comment(...),
            32,
            dependencies: ['content_entries', 'users'],
        );
        $registry->register(
            $this->id(),
            'content_reactions',
            $this->mappings->reaction(...),
            33,
            dependencies: ['content_entries', 'users'],
        );
        $registry->register(
            $this->id(),
            'content_comment_revisions',
            $this->mappings->commentRevision(...),
            34,
            dependencies: ['content_comments', 'users'],
        );
        $registry->register($this->id(), 'servers', $this->mappings->server(...), 40);
        $registry->register(
            $this->id(),
            'navigation_links',
            $this->mappings->navigation(...),
            45,
            replacements: ['navigation_link_role' => [], 'navigation_links' => []],
            dependencies: ['content_entries'],
        );
        $registry->register(
            $this->id(),
            'navigation_link_role',
            $this->mappings->navigationRole(...),
            46,
            dependencies: ['navigation_links', 'roles'],
        );
        $registry->register($this->id(), 'site_redirects', $this->mappings->redirect(...), 47);
    }
}
