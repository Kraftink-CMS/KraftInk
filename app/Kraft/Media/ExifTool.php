<?php

namespace App\Kraft\Media;

use Symfony\Component\Process\ExecutableFinder;
use Symfony\Component\Process\Exception\RuntimeException as ProcessException;
use Symfony\Component\Process\Process;

final class ExifTool
{
    public static function strip(ImageFile $file): ?string
    {
        $binary = function_exists('proc_open') ? (new ExecutableFinder())->find('exiftool') : null;
        if ($binary === null) {
            return null;
        }

        $process = new Process([
            $binary, '-config', '', '-q', '-all=', '-Trailer:All=', '-JUMBF:All=',
            '-tagsFromFile', '@', '-IFD0:Orientation', '-ExifIFD:ColorSpace', '-ExifIFD:Gamma',
            '-o', '-', '--', $file->path,
        ], timeout: 10);

        try {
            return $process->run() === 0 ? ($process->getOutput() ?: null) : null;
        } catch (ProcessException) {
            return null;
        }
    }
}
