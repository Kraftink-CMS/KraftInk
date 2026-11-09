<?php

namespace App\Kraft;

use App\Models\Audit\AuditEntry;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Lang;
use Illuminate\Support\Str;
use App\Kraft\System\AuditWebhook;
use App\Models\Accounts\User;

class Audit
{
    public static function record(string $action, array $context = [], ?User $actor = null): int
    {
        return DB::transaction(
            function () use ($action, $context, $actor) {
                $userId = $actor?->id ?? auth()->id();
                $action = mb_substr($action, 0, 255);
                $context = self::sanitize($context);
                $entry = AuditEntry::query()->create([
                    'user_id' => $userId,
                    'action' => $action,
                    'context' => $context,
                    'ip' => request()->ip(),
                    'created_at' => now(),
                ]);
                app(AuditWebhook::class)->enqueue($entry);

                return $entry->getKey();
            },
        );
    }

    public static function label(string $action): string
    {
        if (str_contains($action, '.')) {
            [$namespace, $key] = explode('.', $action, 2);
            if (Lang::has($namespace.'::admin.audit.'.$key)) {
                return __($namespace.'::admin.audit.'.$key);
            }
            if (Lang::has($namespace.'::audit.'.$key)) {
                return __($namespace.'::audit.'.$key);
            }
        }

        return Lang::has('admin.audit.events.'.$action)
            ? __('admin.audit.events.'.$action)
            : Str::headline($action);
    }

    public static function sanitize(array $values, int $depth = 0): array
    {
        if ($depth > 6) {
            return ['truncated' => true];
        }
        $result = [];
        foreach (array_slice($values, 0, 100, true) as $key => $value) {
            $key = is_int($key) ? $key : mb_substr((string) $key, 0, 100);
            if (
                preg_match(
                    '/password|secret|token|authorization|cookie|credential|private_key|api_key|site_key|recovery|two_factor|totp|signature|^otp$|^sql$/i',
                    (string) $key,
                )
            ) {
                $result[$key] = '[redacted]';
            } elseif (is_array($value)) {
                $result[$key] = self::sanitize($value, $depth + 1);
            } elseif (is_string($value)) {
                $result[$key] = mb_substr(
                    preg_replace(
                        '/([?&](?:[^=&]*(?:token|password|secret|signature|credential|api_key|site_key)|code)=)[^&#\s]*/i',
                        '$1[redacted]',
                        $value,
                    ),
                    0,
                    2000,
                );
            } elseif (is_scalar($value) || $value === null) {
                $result[$key] = $value;
            } else {
                $result[$key] = '[unsupported]';
            }
        }

        return $result;
    }
}
