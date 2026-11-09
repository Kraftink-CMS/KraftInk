<?php

namespace App\Events;

use App\Models\Content\ContentEntry;

class ContentPublished
{
    public function __construct(public ContentEntry $entry, public string $id)
    {
    }
}
