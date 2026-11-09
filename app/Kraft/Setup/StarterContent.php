<?php

namespace App\Kraft\Setup;

use App\Models\Setting;
use App\Kraft\Media\MediaLibrary;
use App\Models\Content\ContentEntry;
use App\Models\Site\Slide;
use App\Models\Accounts\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;

class StarterContent
{
    public function create(User $owner, string $locale): void
    {
        DB::transaction(function () use ($owner, $locale) {
            if (Setting::query()->where('key', 'setup.starter_content')->exists()) {
                return;
            }

            $images = [];
            foreach (['welcome', 'community', 'adventure'] as $position => $name) {
                $image = app(MediaLibrary::class)->store(
                    new UploadedFile(resource_path('images/starter/'.$name.'.webp'), $name.'.webp', test: true),
                    $owner->id,
                    __('system.install.starter.slides.'.$name.'.alt', [], $locale),
                );
                $images[$name] = $image;
                Slide::query()->create([
                    'media_asset_id' => $image->id,
                    'title' => __('system.install.starter.slides.'.$name.'.title', [], $locale),
                    'description' => __('system.install.starter.slides.'.$name.'.description', [], $locale),
                    'alt' => $image->alt,
                    'url' => match ($name) {
                        'welcome' => '/news/'.__('system.install.starter.news.slug', [], $locale),
                        'community' => '/register',
                        'adventure' => '/#news',
                    },
                    'link_label' => __('system.install.starter.slides.'.$name.'.link', [], $locale),
                    'locale' => $locale,
                    'position' => $position,
                    'enabled' => true,
                ]);
            }

            $news = ContentEntry::query()->create([
                'kind' => 'news',
                'locale' => $locale,
                'slug' => __('system.install.starter.news.slug', [], $locale),
                'title' => __('system.install.starter.news.title', [], $locale),
                'summary' => __('system.install.starter.news.summary', [], $locale),
                'body' => '<p>'.
                    e(__('system.install.starter.news.intro', [], $locale)).
                    '</p><p>'.
                    e(__('system.install.starter.news.body', [], $locale)).
                    '</p>',
                'cover_media_id' => $images['welcome']->id,
                'author_id' => $owner->id,
                'published_at' => now(),
                'visibility' => 'public',
            ]);

            $news->publication()->create(['dispatched_at' => now()]);

            Setting::query()->create(['key' => 'setup.starter_content', 'value' => true]);
        });
    }
}
