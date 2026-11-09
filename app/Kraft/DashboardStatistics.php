<?php

namespace App\Kraft;

use App\Models\Audit\AuditEntry;
use App\Models\Accounts\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use JsonException;

class DashboardStatistics
{
    /**
     * @throws JsonException
     */
    public function for(User $user, int $period = 30): ?array
    {
        if (!$user->hasPermission('users.manage')) {
            return null;
        }
        $period = in_array($period, [7, 30, 90], true) ? $period : 30;
        $zone = app(SiteDates::class)->timezone();
        $now = CarbonImmutable::now('UTC');
        $end = $now->setTimezone($zone)->startOfDay()->addDay();
        $start = $end->subDays($period);
        $database = DB::connection()->getConfig();
        $identity = hash(
            'sha256',
            json_encode(
                array_intersect_key($database, array_flip(['driver', 'host', 'port', 'database', 'prefix'])),
                JSON_THROW_ON_ERROR,
            ),
        );

        return Cache::remember(
            'kraft.dashboard.v1.'.$identity.'.'.$zone.'.'.$period.'.'.$start->format('Y-m-d'),
            60,
            function () use ($zone, $period, $now, $start): array {
                $days = [];
                $boundaries = [];
                for ($index = 0; $index < $period; $index++) {
                    $day = $start->addDays($index);
                    $days[] = $day->format('Y-m-d');
                    $boundaries[] = $day->addDay()->utc()->toDateTimeString();
                }
                $from = $start->utc()->toDateTimeString();
                $until = $now->toDateTimeString();
                $members = User::query()
                    ->where('account_type', 'human')
                    ->whereNull('anonymized_at')
                    ->where('created_at', '>=', $from)
                    ->where('created_at', '<=', $until);
                $logins = AuditEntry::query()
                    ->where('action', 'auth.login')
                    ->whereNotNull('user_id')
                    ->where('created_at', '>=', $from)
                    ->where('created_at', '<=', $until);

                return [
                    'period' => $period,
                    'timezone' => $zone,
                    'days' => $days,
                    'registrations' => $this->counts($members, $boundaries),
                    'logins' => $this->counts($logins, $boundaries),
                ];
            },
        );
    }

    private function counts(Builder $query, array $boundaries): array
    {
        $cases = [];
        foreach ($boundaries as $index => $boundary) {
            $cases[] = 'WHEN created_at < ? THEN '.$index;
        }
        $totals = $query
            ->selectRaw('CASE '.implode(' ', $cases).' END AS bucket, COUNT(*) AS total', $boundaries)
            ->groupBy('bucket')
            ->get()
            ->pluck('total', 'bucket');

        return array_map(fn ($index) => (int) ($totals[$index] ?? 0), array_keys($boundaries));
    }
}
