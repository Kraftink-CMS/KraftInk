<?php

namespace App\Kraft\Setup;

use Illuminate\Support\Facades\File;
use JsonException;

class Installation
{
    private ?bool $installed = null;

    public function installed(bool $refresh = false): bool
    {
        if ($refresh) {
            $this->installed = null;
        }

        return $this->installed ??= InstallationFiles::installed(storage_path());
    }

    /**
     * @throws JsonException
     */
    public function complete(string $version): void
    {
        InstallationFiles::prepare($this->path());
        File::replace(
            $this->path(),
            json_encode(['version' => $version, 'installed_at' => now()->toIso8601String()], JSON_THROW_ON_ERROR),
            0600,
        );
        $this->installed = true;
    }

    public function path(): string
    {
        return storage_path(InstallationFiles::MARKER);
    }
}
