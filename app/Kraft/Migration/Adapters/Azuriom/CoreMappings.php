<?php

namespace App\Kraft\Migration\Adapters\Azuriom;

use App\Kraft\Migration\CoreMappings as CoreRecords;
use App\Kraft\Migration\ImportContext;
use App\Kraft\Games\GameRegistry;
use App\Kraft\Redirects;
use WeakMap;

final class CoreMappings
{
    private ?WeakMap $sourceLocks = null;

    public function __construct(private readonly CoreRecords $records, private readonly ContentAccess $access)
    {
    }

    public function role(array $row, ImportContext $context): array
    {
        $color = $row['color'] ?? null;
        if (is_string($color) && preg_match('/^[a-fA-F0-9]{6}$/D', $color)) {
            $row['color'] = '#'.$color;
        } elseif (is_numeric($color) && (int) $color >= 0 && (int) $color <= 16777215) {
            $row['color'] = sprintf('#%06x', (int) $color);
        }

        return $this->records->role($row, $context);
    }

    public function user(array $row, ImportContext $context): array
    {
        $user = $context->adapter->user($row, $context);
        $user['locked'] = $user['locked'] || $this->sourceLocked($row['id'], $context);

        return $this->records->user($row, $context, $user);
    }

    public function server(array $row, ImportContext $context): array
    {
        $games = app(GameRegistry::class);
        $game = $context->options['game'] ?? $games->activeId();
        $defaultPort = $games->all()[$game]['default_port'] ?? 25565;
        $row['show_port'] = isset($row['port']) && (int) $row['port'] !== $defaultPort;
        $row['port'] ??= $defaultPort;
        $result = $this->records->server($row, $context);
        $settings = array_column($context->indexedSourceRows('settings'), 'value', 'name');
        if ((string) ($settings['servers.default'] ?? '') === (string) $row['id']) {
            $context->setting('servers.primary', $result['target_id']);
        }

        return $result;
    }

    public function content(array $row, ImportContext $context, string $kind): array
    {
        $result = $this->records->content($row, $context, $kind, $this->access->resolve($row, $context, $kind));
        if ($kind === 'page' && !empty($row['slug'])) {
            $path = '/'.ltrim((string) $row['slug'], '/');
            $slug = $context->target->table('content_entries')->where('id', $result['target_id'])->value('slug');
            $result['metadata'] = [
                'legacy_path' => $path,
                'legacy_redirect' => app(Redirects::class)->preserve($path, '/p/'.$slug),
            ];
        }

        return $result;
    }

    public function lockUser(array $row, ImportContext $context): array
    {
        if (!empty($row['removed_at'])) {
            return ['target_table' => 'users', 'target_id' => $context->required('users', $row['user_id'])];
        }

        return $this->records->lockUser($row, $context);
    }

    public function pageAccess(array $row, ImportContext $context): array
    {
        $entry = $context->required('pages', $row['page_id']);
        $role = $context->required($context->adapter->roleTable(), $row['role_id']);
        $context->target
            ->table('content_entry_role')
            ->insertOrIgnore(['content_entry_id' => $entry, 'role_id' => $role]);
        $context->target
            ->table('content_entries')
            ->where('id', $entry)
            ->update(['visibility' => 'restricted', 'roles_restricted' => true]);

        return ['target_table' => 'content_entries', 'target_id' => $entry];
    }

    private function sourceLocked(int|string $id, ImportContext $context): bool
    {
        $this->sourceLocks ??= new WeakMap();
        if (!isset($this->sourceLocks[$context])) {
            $locks = [];
            foreach ($context->sourceRows('bans') ?? [] as $ban) {
                if (!empty($ban['user_id']) && empty($ban['removed_at'])) {
                    $locks[(string) $ban['user_id']] = true;
                }
            }
            $this->sourceLocks[$context] = $locks;
        }

        return isset($this->sourceLocks[$context][(string) $id]);
    }
}
