<?php

namespace App\Kraft\Trash;

final readonly class TrashPurgeResult
{
    public function __construct(public int $purged, public int $skipped, public int $failed)
    {
    }
}
