<?php

namespace App\Kraft\Site;

use App\Kraft\Media\Images;
use App\Kraft\Setup\Installation;
use App\Kraft\Settings;
use App\Kraft\SiteDates;
use App\Kraft\TextTranslations;
use App\Models\Media\MediaAsset;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\File;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Illuminate\Validation\ValidationException;

class SiteIdentity
{
    private ?int $logoId = null;

    private ?MediaAsset $logoAsset = null;

    public function __construct(private readonly Settings $settings)
    {
    }

    public static function textFields(): array
    {
        return [
            'name' => ['max' => 120],
            'description' => ['max' => 500, 'type' => 'textarea'],
            'copyright' => ['max' => 300],
            'seo_title' => ['max' => 120],
            'seo_description' => ['max' => 320, 'type' => 'textarea'],
        ];
    }

    public function text(string $field): string
    {
        $key = match ($field) {
            'name' => 'site.name',
            'description' => 'site.description',
            'copyright' => 'site.copyright',
            'seo_title' => 'seo.title',
            'seo_description' => 'seo.description',
        };

        /** @var TextTranslations $translations */
        $translations = app(TextTranslations::class);

        return $translations->resolve(
            (string) $this->settings->get($key, $field === 'name' ? 'KraftInk' : ''),
            $this->settings->get('site.translations', []),
            $field,
        );
    }

    public function normalizeUrl(string $input): string
    {
        $value = trim($input);
        $scheme = strtolower(parse_url(config('app.url'), PHP_URL_SCHEME) ?: 'https');
        if (preg_match('~^https?:/*~i', $value)) {
            $value = preg_replace('~^(https?):/*~i', '$1://', $value);
        } elseif (str_starts_with($value, '//')) {
            $value = $scheme.':'.$value;
        } elseif (!preg_match('~^[a-z][a-z0-9+.-]*://~i', $value)) {
            if (preg_match('~^[a-z][a-z0-9+.-]*:~i', $value) && !preg_match('~^[^/]+:[0-9]+(?:/|$)~', $value)) {
                $this->invalidUrl();
            }
            $value = $scheme.'://'.$value;
        }
        $parts = parse_url($value);
        if (
            !$parts ||
            !in_array(strtolower($parts['scheme'] ?? ''), ['http', 'https'], true) ||
            empty($parts['host']) ||
            array_key_exists('user', $parts) ||
            array_key_exists('pass', $parts) ||
            preg_match('/[\\\\\x00-\x20\x7f]/', $value)
        ) {
            $this->invalidUrl();
        }
        $scheme = strtolower($parts['scheme']);
        $host = strtolower($parts['host']);
        if (preg_match('/[^\x00-\x7f]/', $host) && function_exists('idn_to_ascii')) {
            $host = idn_to_ascii($host, IDNA_DEFAULT, INTL_IDNA_VARIANT_UTS46) ?: '';
        }
        $segments = [];
        foreach (explode('/', $parts['path'] ?? '') as $segment) {
            $decoded = rawurldecode($segment);
            if (preg_match('/[\\\\\x00-\x1f\x7f]/', $decoded)) {
                $this->invalidUrl();
            }
            if ($decoded === '' || $decoded === '.') {
                continue;
            }
            if ($decoded === '..') {
                array_pop($segments);

                continue;
            }
            $segments[] = rawurlencode($decoded);
        }
        $port =
            isset($parts['port']) && $parts['port'] !== ($scheme === 'https' ? 443 : 80) ? ':'.$parts['port'] : '';
        $url = $scheme.'://'.$host.$port.($segments ? '/'.implode('/', $segments) : '');
        if (!filter_var($url, FILTER_VALIDATE_URL)) {
            $this->invalidUrl();
        }

        return $url;
    }

    public function apply(): void
    {
        $original = config('kraft.original_app_url') ?? config('app.url');
        config(['kraft.original_app_url' => $original]);
        $preferred = $this->settings->get('site.url');
        if (!is_string($preferred) || $preferred === '') {
            URL::useOrigin(null);
            URL::forceScheme(null);
            config(['app.url' => $original]);

            return;
        }
        try {
            $url = $this->normalizeUrl($preferred);
        } catch (ValidationException) {
            $url = $original;
        }
        config(['app.url' => $url]);
        URL::useOrigin($url);
        URL::forceScheme(parse_url($url, PHP_URL_SCHEME));
    }

    public function copyright(): string
    {
        $year = CarbonImmutable::now(app(SiteDates::class)->timezone())->format('Y');
        $site = $this->text('name');
        $text = $this->text('copyright');

        return trim($text) !== ''
            ? strtr($text, ['{year}' => $year, '{site}' => $site])
            : __('admin.settings.identity.default_copyright', ['year' => $year, 'site' => $site]);
    }

    /** @return Builder<MediaAsset> */
    public function logos(): Builder
    {
        return MediaAsset::query()
            ->whereIn('mime', ['image/png', 'image/jpeg', 'image/webp'])
            ->whereBetween('width', [16, 4096])
            ->whereBetween('height', [16, 4096])
            ->where('size', '<=', 2097152);
    }

    public function logo(): ?MediaAsset
    {
        if (!app(Installation::class)->installed() && !app()->runningUnitTests()) {
            return null;
        }
        $id = (int) $this->settings->get('site.logo_id');
        if (!$id) {
            return null;
        }
        if ($this->logoId === $id) {
            return $this->logoAsset;
        }
        $this->logoId = $id;
        $asset = $this->logos()->find($id);

        return $this->logoAsset = $asset && Storage::disk('local')->exists($asset->path) ? $asset : null;
    }

    public function validateLogo(?int $id): ?MediaAsset
    {
        if (!$id) {
            return null;
        }
        $asset = $this->logos()->find($id);
        $image = $asset ? app(Images::class)->inspect(new File(Storage::disk('local')->path($asset->path), false)) : null;
        if (
            !$asset ||
            !$image ||
            $image->mime !== $asset->mime ||
            $image->width !== (int) $asset->width ||
            $image->height !== (int) $asset->height
        ) {
            throw ValidationException::withMessages(['logo_id' => __('admin.settings.identity.logo_invalid')]);
        }

        return $asset;
    }

    private function invalidUrl(): never
    {
        throw ValidationException::withMessages(['site_url' => __('admin.settings.identity.url_invalid')]);
    }
}
