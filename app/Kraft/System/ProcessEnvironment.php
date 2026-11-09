<?php

namespace App\Kraft\System;

use Illuminate\Support\Facades\File;
use RuntimeException;

class ProcessEnvironment
{
    public function variables(): array
    {
        $path = storage_path('app/private/processes');
        for ($cursor = $path; $cursor !== dirname($cursor); $cursor = dirname($cursor)) {
            if (is_link($cursor)) {
                throw new RuntimeException(__('system.install.requirements.path_failure', ['path' => $path]));
            }
        }
        if (file_exists($path) && !is_dir($path)) {
            throw new RuntimeException(__('system.install.requirements.path_failure', ['path' => $path]));
        }
        $mask = umask(0077);
        try {
            File::ensureDirectoryExists($path, 0700);
        } finally {
            umask($mask);
        }
        if (!chmod($path, 0700) || !is_writable($path)) {
            throw new RuntimeException(__('system.install.requirements.path_failure', ['path' => $path]));
        }

        return array_fill_keys(['TEMP', 'TMP', 'TMPDIR'], $path);
    }
}
