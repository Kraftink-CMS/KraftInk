<?php

namespace App\Kraft\Currencies;

use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

class CurrencyRegistry
{
    public const array DEFAULT_CODES = ['EUR', 'USD', 'GBP', 'CAD', 'CHF'];

    private array $currencies = [];

    public function __construct()
    {
        foreach (self::DEFAULT_CODES as $code) {
            $this->register($code, 2);
        }
    }

    public function register(string $code, int $decimals, ?string $labelKey = null): void
    {
        if (
            !preg_match('/^[A-Z]{3}$/D', $code) ||
            ($labelKey !== null &&
                (!preg_match('/^(?:[a-z][a-z0-9_-]*::)?[a-zA-Z0-9_.-]+$/D', $labelKey) || strlen($labelKey) > 180))
        ) {
            throw new InvalidArgumentException(__('common.currencies.invalid_registration'));
        }
        if ($decimals !== 2) {
            throw new InvalidArgumentException(
                __('common.currencies.unsupported_decimals', ['currency' => $code, 'decimals' => $decimals]),
            );
        }
        if (isset($this->currencies[$code]) && $this->currencies[$code]->labelKey !== $labelKey) {
            throw new InvalidArgumentException(__('common.currencies.duplicate', ['currency' => $code]));
        }
        $this->currencies[$code] ??= new Currency($code, $decimals, $labelKey);
    }

    public function all(): array
    {
        return $this->currencies;
    }

    public function codes(): array
    {
        return array_keys($this->currencies);
    }

    public function find(string $code): ?Currency
    {
        return $this->currencies[$code] ?? null;
    }

    public function get(string $code): Currency
    {
        return $this->find($code) ??
            throw new InvalidArgumentException(__('common.currencies.unknown', ['currency' => $code]));
    }

    public function assertSupported(string $code): Currency
    {
        if (!($currency = $this->find($code))) {
            throw ValidationException::withMessages([
                'currency' => __('common.currencies.unknown', ['currency' => $code]),
            ]);
        }

        return $currency;
    }
}
