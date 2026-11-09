<?php

namespace App\Kraft\Extensions;

use App\Kraft\Auth\PermissionAccess;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\HtmlString;
use InvalidArgumentException;
use Throwable;

class SlotRegistry
{
    private array $contributions = [];

    private array $rendering = [];

    private array $errors = [];

    public function __construct(
        private readonly ExtensionManager $extensions,
        private readonly ContributionRenderer $renderer,
    ) {
    }

    public function add(
        string $owner,
        string $slot,
        string $id,
        mixed $render,
        ?string $permission = null,
        int $priority = 0,
        string $mode = 'after',
    ): void {
        if (
            !preg_match('/^[a-z][a-z0-9-]{1,49}$/D', $owner) ||
            !preg_match('/^[a-z][a-z0-9_.-]{1,159}$/D', $slot) ||
            !preg_match('/^[a-z][a-z0-9_.-]{0,79}$/D', $id) ||
            !in_array($mode, ['before', 'after', 'replace'], true) ||
            (!is_callable($render) && !is_string($render)) ||
            ($permission !== null && !preg_match('/^[a-z][a-z0-9_.-]{1,119}$/D', $permission))
        ) {
            throw new InvalidArgumentException('Invalid extension slot contribution.');
        }
        $this->contributions[$slot][$owner.':'.$id] = compact(
            'owner',
            'id',
            'render',
            'permission',
            'priority',
            'mode',
        );
    }

    public function render(string $slot, array $context = [], string $default = ''): HtmlString
    {
        if (isset($this->rendering[$slot]) || empty($this->contributions[$slot])) {
            return new HtmlString($default);
        }
        $this->rendering[$slot] = true;
        try {
            $extensions = $this->extensions->runtimeContributors();
            if (!$extensions) {
                return new HtmlString($default);
            }
            $target = str_starts_with($slot, 'admin.') ? 'admin' : 'site';
            $selectedTheme = $this->extensions->selectedTheme($target);
            $permissions = collect(app(PermissionAccess::class)->permissions(auth()->user()));
            $entries = array_filter($this->contributions[$slot], function ($entry) use (
                $extensions,
                $permissions,
                $selectedTheme,
                $target,
            ) {
                $extension = $extensions[$entry['owner']] ?? null;

                return $extension &&
                    ($extension['type'] !== 'theme' ||
                        ($extension['id'] === $selectedTheme && $extension['target'] === $target)) &&
                    (!$entry['permission'] ||
                        $permissions->contains('*') ||
                        $permissions->contains($entry['permission']));
            });
            uasort(
                $entries,
                fn ($left, $right) => [$left['priority'], $left['owner'], $left['id']] <=> [
                    $right['priority'],
                    $right['owner'],
                    $right['id'],
                ],
            );
            $before = '';
            $after = '';
            $replacement = null;
            foreach ($entries as $entry) {
                $output = $this->contribution($slot, $entry, $context);
                if ($output === null) {
                    continue;
                }
                if ($entry['mode'] === 'before') {
                    $before .= $output;
                } elseif ($entry['mode'] === 'after') {
                    $after .= $output;
                } else {
                    $replacement = $output;
                }
            }

            return new HtmlString($before.($replacement ?? $default).$after);
        } finally {
            unset($this->rendering[$slot]);
        }
    }

    public function diagnostics(): array
    {
        return $this->errors;
    }

    private function contribution(string $slot, array $entry, array $context): ?string
    {
        try {
            return $this->renderer->render(fn () => is_string($entry['render']) && !is_callable($entry['render'])
                    ? view($entry['render'], $context)
                    : app()->call($entry['render'], ['context' => $context]),
            );
        } catch (Throwable $exception) {
            $this->errors[$entry['owner'].':'.$slot.':'.$entry['id']] = 'render_failed';
            try {
                Log::warning('Extension slot unavailable', [
                    'extension' => $entry['owner'],
                    'slot' => $slot,
                    'contribution' => $entry['id'],
                    'exception' => $exception::class,
                ]);
            } catch (Throwable) {
            }

            return null;
        }
    }
}
