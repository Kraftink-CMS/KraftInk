<?php

namespace App\Kraft;

use App\Models\Setting;
use App\Kraft\Setup\Installation;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use App\Events\ConfigurationChanged;
use Illuminate\Support\Facades\Schema;

class Settings
{
    private ?array $values = null;

    public function all(): array
    {
        if (!app(Installation::class)->installed() && !app()->runningUnitTests()) {
            return [];
        }
        if (app()->runningUnitTests() && !Schema::hasTable('settings')) {
            return [];
        }
        $load = fn () => Setting::query()->pluck('value', 'key')->all();

        return $this->values ??= DB::transactionLevel() > 0 ? $load() : Cache::remember('kraft.settings', 300, $load);
    }

    public function get(string $key, mixed $default = null): mixed
    {
        return $this->all()[$key] ?? $default;
    }

    public function refresh(): void
    {
        Cache::forget('kraft.settings');
        $this->values = null;
    }

    public function set(string $key, mixed $value): void
    {
        Setting::query()->updateOrCreate(['key' => $key], ['value' => $value]);
        Cache::forget('kraft.settings');
        $this->values = null;
        $changed = fn () => event(new ConfigurationChanged($key));
        if (DB::transactionLevel() > 0) {
            DB::afterCommit($changed);
        } else {
            $changed();
        }
        if (DB::transactionLevel() > 0) {
            DB::afterCommit(fn () => Cache::forget('kraft.settings'));
            DB::afterRollBack(function () {
                Cache::forget('kraft.settings');
                $this->values = null;
            });
        }
    }
}
