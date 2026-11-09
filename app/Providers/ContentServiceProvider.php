<?php

namespace App\Providers;

use App\Events\AccountDeleting;
use App\Events\ContentPublished;
use App\Events\ExportingPersonalData;
use App\Kraft\Content\ContentPrivacy;
use App\Kraft\Content\ContentTrash;
use App\Kraft\Content\Discord\Announcements;
use App\Kraft\Trash\TrashRegistry;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;

class ContentServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        app(ContentTrash::class)->register(app(TrashRegistry::class));
        Event::listen(ExportingPersonalData::class, [ContentPrivacy::class, 'export']);
        Event::listen(AccountDeleting::class, [ContentPrivacy::class, 'anonymize']);
        Event::listen(ContentPublished::class, [Announcements::class, 'published']);
    }
}
