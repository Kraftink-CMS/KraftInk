<?php

namespace App\Kraft\System;

use RuntimeException;
use Symfony\Component\Process\ExecutableFinder;
use Symfony\Component\Process\PhpExecutableFinder;

class PhpBinary
{
    public function path(): string
    {
        $configured = config('kraft.php_binary');
        if (is_string($configured) && $configured !== '') {
            $binary = is_file($configured) ? $configured : new ExecutableFinder()->find($configured);
        } else {
            $binary = null;
            if (!in_array(PHP_SAPI, ['cli', 'cli-server', 'phpdbg'], true)) {
                $candidate = dirname(PHP_BINARY).'/php'.(PHP_OS_FAMILY === 'Windows' ? '.exe' : '');
                if (is_file($candidate) && is_executable($candidate)) {
                    $binary = $candidate;
                }
            }
            $binary ??= new PhpExecutableFinder()->find(false);
        }
        if (!is_string($binary) || $binary === '' || !is_file($binary) || !is_executable($binary)) {
            throw new RuntimeException('Cannot locate PHP CLI. Configure KRAFT_PHP_BINARY.');
        }

        return $binary;
    }
}
