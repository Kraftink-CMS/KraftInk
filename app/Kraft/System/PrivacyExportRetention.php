<?php

namespace App\Kraft\System;

use DirectoryIterator;
use RuntimeException;

class PrivacyExportRetention
{
    public function prune(bool $dryRun = false): int
    {
        $privateRoot = realpath(storage_path('app/private'));
        $directory = storage_path('app/private/privacy-exports');
        if (!file_exists($directory)) {
            return 0;
        }
        $root = realpath($directory);
        if (
            !$root ||
            !$privateRoot ||
            !str_starts_with(str_replace('\\', '/', $root), rtrim(str_replace('\\', '/', $privateRoot), '/').'/')
        ) {
            throw new RuntimeException('Invalid private export storage.');
        }
        $cutoff = now()->subHours(max(1, (int) config('privacy.export_retention_hours', 24)))->timestamp;
        $count = 0;
        foreach (new DirectoryIterator($root) as $file) {
            if (
                $file->isLink() ||
                !$file->isFile() ||
                !preg_match('/^[a-f0-9]{40}\.jsonl\.encrypted$/D', $file->getFilename()) ||
                $file->getMTime() >= $cutoff ||
                dirname($file->getRealPath()) !== $root
            ) {
                continue;
            }
            if ($dryRun || unlink($file->getPathname())) {
                $count++;
            }
            if (!$dryRun && $count >= 1000) {
                break;
            }
        }

        return $count;
    }
}
