<?php

namespace App\Kraft;

use Carbon\CarbonImmutable;
use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Throwable;
use InvalidArgumentException;
use DateInvalidTimeZoneException;

class SiteDates
{
    public function __construct(private Settings $settings)
    {
    }

    public static function zones(): array
    {
        static $zones;

        return $zones ??= DateTimeZone::listIdentifiers();
    }

    public function timezone(): string
    {
        $zone = $this->settings->get('site.timezone', 'UTC');

        return is_string($zone) && in_array($zone, self::zones(), true) ? $zone : 'UTC';
    }

    public function format(DateTimeInterface|string|null $value, string $pattern = 'd M Y · H:i T'): string
    {
        if ($value === null || $value === '') {
            return '—';
        }
        try {
            return $this->utc($value)
                ->setTimezone($this->timezone())
                ->locale(app()->getLocale())
                ->translatedFormat($pattern);
        } catch (Throwable) {
            return '—';
        }
    }

    public function forInput(DateTimeInterface|string|null $value): string
    {
        if ($value === null || $value === '') {
            return '';
        }

        return $this->utc($value)->setTimezone($this->timezone())->format('Y-m-d\TH:i:s');
    }

    public function iso(DateTimeInterface|string|null $value): string
    {
        return $value === null || $value === '' ? '' : $this->utc($value)->toIso8601String();
    }

    /**
     * @throws DateInvalidTimeZoneException
     */
    public function occurrence(DateTimeInterface|string|null $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }
        $utc = $this->utc($value)->format('Y-m-d H:i:s');
        $choices = $this->candidates($this->forInput($value));

        return count($choices) > 1 ? collect($choices)->firstWhere('utc', $utc)['choice'] ?? null : null;
    }

    /**
     * @throws DateInvalidTimeZoneException
     */
    public function candidates(string $value): array
    {
        $value = str_replace(' ', 'T', $value);
        if (!preg_match('/^[0-9]{4}-[0-9]{2}-[0-9]{2}T[0-9]{2}:[0-9]{2}(?::[0-9]{2})?$/D', $value)) {
            throw new InvalidArgumentException('Invalid local date.');
        }
        $value = strlen($value) === 16 ? $value.':00' : $value;
        $wall = DateTimeImmutable::createFromFormat('!Y-m-d\TH:i:s', $value, new DateTimeZone('UTC'));
        $errors = DateTimeImmutable::getLastErrors();
        if (
            !$wall ||
            ($errors && ($errors['warning_count'] || $errors['error_count'])) ||
            $wall->format('Y-m-d\TH:i:s') !== $value
        ) {
            throw new InvalidArgumentException('Invalid local date.');
        }
        $zone = new DateTimeZone($this->timezone());
        $timestamp = $wall->getTimestamp();
        $transitions = $zone->getTransitions($timestamp - 172800, $timestamp + 172800);
        $offsets = $transitions ? array_unique(array_column($transitions, 'offset')) : [$zone->getOffset($wall)];
        $candidates = [];
        foreach ($offsets as $offset) {
            $instant = CarbonImmutable::createFromTimestampUTC($timestamp - $offset);
            if ($instant->setTimezone($zone)->format('Y-m-d\TH:i:s') === $value) {
                $candidates[$instant->timestamp] = [
                    'utc' => $instant->format('Y-m-d H:i:s'),
                    'offset' => $instant->setTimezone($zone)->format('P'),
                ];
            }
        }
        ksort($candidates);
        $candidates = array_values($candidates);
        foreach ($candidates as $index => &$candidate) {
            $candidate['choice'] = count($candidates) === 1 ? 'only' : ($index === 0 ? 'earlier' : 'later');
            $candidate['label'] = __('common.dates.'.$candidate['choice'], ['offset' => $candidate['offset']]);
        }

        return $candidates;
    }

    /**
     * @throws DateInvalidTimeZoneException
     */
    public function parseLocal(string $value, ?string $occurrence = null, string $field = 'date'): CarbonImmutable
    {
        try {
            $candidates = $this->candidates($value);
        } catch (InvalidArgumentException) {
            throw ValidationException::withMessages([$field => __('common.dates.invalid')]);
        }
        if ($candidates === []) {
            throw ValidationException::withMessages([
                $field => __('common.dates.nonexistent', ['zone' => $this->timezone()]),
            ]);
        }
        if (count($candidates) > 1 && !in_array($occurrence, ['earlier', 'later'], true)) {
            throw ValidationException::withMessages([
                $field => __('common.dates.ambiguous', ['zone' => $this->timezone()]),
            ]);
        }
        $choice = count($candidates) === 1 ? $candidates[0] : collect($candidates)->firstWhere('choice', $occurrence);

        return CarbonImmutable::parse($choice['utc'], 'UTC');
    }

    /**
     * @throws DateInvalidTimeZoneException
     */
    public function normalize(Request $request, array $fields): void
    {
        if (!$request->has('_site_timezone')) {
            return;
        }
        $raw = [];
        foreach ($fields as $field) {
            if ($request->exists($field)) {
                $value = $request->input($field);
                $raw[$field] = is_string($value) ? $value : '';
            }
        }
        $request->merge(['_local_dates_values' => $raw]);
        if (
            !collect($fields)->contains(
                fn ($field) => $request->input($field) !== null && $request->input($field) !== '',
            )
        ) {
            return;
        }
        if ($request->input('_site_timezone') !== $this->timezone()) {
            throw ValidationException::withMessages([reset($fields) ?: 'date' => __('common.dates.changed_timezone')]);
        }
        $normalized = [];
        foreach ($fields as $field) {
            $value = $request->input($field);
            if ($value === null || $value === '') {
                continue;
            }
            if (!is_string($value)) {
                throw ValidationException::withMessages([$field => __('common.dates.invalid')]);
            }
            $occurrence = $request->input($field.'_occurrence');
            if ($occurrence !== null && !is_string($occurrence)) {
                throw ValidationException::withMessages([$field => __('common.dates.invalid')]);
            }
            $utc = $this->parseLocal($value, $occurrence, $field);
            $normalized[$field] = $utc->format('Y-m-d H:i:s');
        }
        $request->merge($normalized);
    }

    private function utc(DateTimeInterface|string $value): CarbonImmutable
    {
        return $value instanceof DateTimeInterface
            ? CarbonImmutable::instance($value)->utc()
            : CarbonImmutable::parse($value, 'UTC')->utc();
    }
}
