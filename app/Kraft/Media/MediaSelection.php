<?php

namespace App\Kraft\Media;

use Symfony\Component\HttpKernel\Exception\HttpException;
use App\Kraft\Favicon;
use App\Kraft\Site\SiteIdentity;
use App\Models\Media\MediaAsset;
use App\Models\Accounts\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

class MediaSelection
{
    private array $contexts = [
        'site-logo' => ['permission' => 'settings.manage', 'profile' => 'logo'],
        'site-favicon' => ['permission' => 'settings.manage', 'profile' => 'favicon'],
        'home-background' => ['permission' => 'settings.manage', 'profile' => 'landscape'],
        'content-cover' => ['permission' => 'content.manage', 'profile' => 'image'],
        'slide' => ['permission' => 'content.manage', 'profile' => 'landscape'],
        'library' => ['permission' => 'media.manage', 'profile' => 'image'],
        'theme-media' => ['permission' => 'settings.manage', 'profile' => 'image'],
        'theme-image' => ['permission' => 'settings.manage', 'profile' => 'image'],
    ];

    public function register(string $context, string $permission, string $profile = 'image'): void
    {
        if (
            !preg_match('/^[a-z][a-z0-9_.-]{0,79}$/D', $context) ||
            !preg_match('/^[a-z][a-z0-9_.-]{1,119}$/D', $permission) ||
            isset($this->contexts[$context]) ||
            !in_array($profile, ['image', 'landscape', 'logo', 'favicon'], true)
        ) {
            throw new InvalidArgumentException('Invalid media selection context.');
        }
        $this->contexts[$context] = compact('permission', 'profile');
    }

    public function authorize(User $user, string $context): void
    {
        $configuration = $this->configuration($context);
        if (!$user->hasPermission($configuration['permission'])) {
            throw new HttpException(403);
        }
    }

    /** @return Builder<MediaAsset> */
    public function query(string $context): Builder
    {
        $profile = $this->configuration($context)['profile'];
        $query = match ($profile) {
            'logo' => app(SiteIdentity::class)->logos(),
            'favicon' => app(Favicon::class)->available(),
            default => MediaAsset::query()
                ->whereIn('mime', $this->types($profile))
                ->where('width', '>=', 1)
                ->where('height', '>=', 1)
                ->where('size', '<=', $this->maxKilobytes($profile) * 1024),
        };

        return $query->whereRaw('width * height <= ?', [config('community.media_max_pixels', 40000000)]);
    }

    public function selected(mixed $id, string $context): ?MediaAsset
    {
        if (!is_scalar($id) || !ctype_digit((string) $id) || (int) $id < 1) {
            return null;
        }
        $asset = $this->query($context)->find((int) $id);

        return $asset && Storage::disk('local')->exists($asset->path) ? $asset : null;
    }

    public function rules(string $context, bool $required = true): array
    {
        $profile = $this->configuration($context)['profile'];
        $rules = [
            'bail',
            $required ? 'required' : 'nullable',
            'file',
            'mimetypes:'.implode(',', $this->types($profile)),
            'max:'.$this->maxKilobytes($profile),
        ];
        if ($profile === 'logo') {
            $rules[] = new ImageDimensions(16, 16, 4096, 4096, 'admin.settings.identity.logo_invalid');
        } elseif ($profile === 'favicon') {
            $rules[] = new ImageDimensions(16, 16, 2048, 2048, 'admin.settings.identity.favicon.invalid', square: true);
        }

        return $rules;
    }

    public function describe(MediaAsset $asset): array
    {
        return [
            'id' => $asset->id,
            'name' => $asset->original_name,
            'alt' => $asset->displayText('alt'),
            'url' => route('site.media.show', $asset),
            'width' => (int) $asset->width,
            'height' => (int) $asset->height,
            'mime' => $asset->mime,
            'size' => (int) $asset->size,
        ];
    }

    private function configuration(string $context): array
    {
        if (!isset($this->contexts[$context])) {
            throw ValidationException::withMessages([
                'context' => __('validation.in', ['attribute' => 'context']),
            ]);
        }

        return $this->contexts[$context];
    }

    private function types(string $profile): array
    {
        return match ($profile) {
            'favicon' => ['image/png'],
            'logo', 'landscape' => ['image/png', 'image/jpeg', 'image/webp'],
            default => app(Images::class)->supportedMimeTypes(),
        };
    }

    private function maxKilobytes(string $profile): int
    {
        return in_array($profile, ['logo', 'favicon'], true)
            ? 2048
            : (int) config('community.media_max_kilobytes', 8192);
    }
}
