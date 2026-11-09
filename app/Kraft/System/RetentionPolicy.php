<?php

namespace App\Kraft\System;

use App\Kraft\Settings;
use InvalidArgumentException;

class RetentionPolicy
{
    public function __construct(private Settings $settings)
    {
    }

    public function days(string $category): int
    {
        if (!in_array($category, ['history', 'temporary', 'technical'], true)) {
            throw new InvalidArgumentException('Unknown retention category.');
        }

        return $this->value($category.'_days', 3650);
    }

    public function historyLimit(): int
    {
        return $this->value('history_limit', 1000);
    }

    private function value(string $key, int $maximum): int
    {
        $value = $this->settings->get('retention.'.$key, config('retention.'.$key));
        $validated = filter_var($value, FILTER_VALIDATE_INT, [
            'options' => ['min_range' => 1, 'max_range' => $maximum],
        ]);
        if ($validated === false || is_bool($value)) {
            throw new InvalidArgumentException('Invalid retention value.');
        }

        return $validated;
    }
}
