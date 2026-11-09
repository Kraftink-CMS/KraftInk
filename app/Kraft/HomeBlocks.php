<?php

namespace App\Kraft;

use App\Models\Media\MediaAsset;
use App\Models\Accounts\User;
use Illuminate\Support\Facades\Storage;

class HomeBlocks
{
    public const array IMAGE_TYPES = ['image/png', 'image/jpeg', 'image/webp'];

    public function __construct(private readonly Settings $settings, private readonly TextTranslations $translations)
    {
    }

    public function content(?User $user = null): array
    {
        $locale = app()->getLocale();
        $messages = $this->settings->get('home.messages', []);
        $alerts = $this->settings->get('home.alerts', []);
        $message = $this->localized($messages);
        $alert = !$user && $this->settings->get('home.alert_enabled', false) ? $this->localized($alerts) : '';
        $imageId = $this->settings->get('home.background_media_id');
        $background = $imageId ? MediaAsset::query()->whereIn('mime', self::IMAGE_TYPES)->find($imageId) : null;
        if ($background && !Storage::disk('local')->exists($background->path)) {
            $background = null;
        }

        return [
            'message' => $message,
            'alert' => $alert,
            'alert_key' => hash('sha256', $locale.$alert),
            'background' => $background,
        ];
    }

    public function heading(): array
    {
        $policy = app(LocalePolicy::class);
        $primary = [
            'title' => (string) $this->settings->get('home.title', ''),
            'description' => (string) $this->settings->get('home.description', ''),
        ];
        if (!$policy->allowsChoice()) {
            return $primary;
        }
        $locale = $policy->defaultLocale();
        $values = $this->settings->get('home.heading_translations', []);
        unset($values[$locale]);
        foreach ($primary as $field => $value) {
            $primary[$field] = $this->translations->resolve($value, $values, $field, originLocale: $locale);
        }

        return $primary;
    }

    private function localized(array $translations): string
    {
        $policy = app(LocalePolicy::class);
        $locales = $policy->allowsChoice()
            ? array_unique([app()->getLocale(), 'en', $policy->defaultLocale()])
            : [$policy->defaultLocale()];
        foreach ($locales as $locale) {
            $content = Html::clean($translations[$locale] ?? '');
            $text = html_entity_decode(strip_tags($content, ['img', 'hr']), ENT_QUOTES | ENT_HTML5, 'UTF-8');
            if (preg_match('/[^\s\p{Z}]/u', $text) === 1) {
                return $content;
            }
        }

        return '';
    }
}
