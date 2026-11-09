<?php

namespace App\Kraft\Currencies;

use Illuminate\Support\Facades\Lang;

final readonly class Currency
{
    public function __construct(public string $code, public int $decimals, public ?string $labelKey = null)
    {
    }

    public function label(): string
    {
        if ($this->labelKey !== null) {
            return __($this->labelKey);
        }

        $key = 'common.currencies.names.'.$this->code;

        return Lang::has($key) ? __($key) : $this->code;
    }
}
