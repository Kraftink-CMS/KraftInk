<?php

namespace App\Kraft\Updates;

use App\Kraft\Games\GameRegistry;
use App\Kraft\Settings;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use RuntimeException;

class KraftLinkUpdates
{
    private const string API = 'https://api.github.com/repos/Kraftink-CMS/KraftLink/releases/latest';

    private const string RELEASES = 'https://github.com/Kraftink-CMS/KraftLink/releases';

    private const string ENABLED = 'updates.kraftlink.enabled';

    private const string IGNORED_VERSION = 'updates.kraftlink.ignored_version';

    public function __construct(private readonly GameRegistry $games, private readonly Settings $settings)
    {
    }

    public function supportsActiveGame(): bool
    {
        return isset($this->games->linkableGames()[$this->games->activeId()]['connections']['kraftlink']);
    }

    public function enabled(): bool
    {
        return $this->settings->get(self::ENABLED, true) === true;
    }

    public function canNotify(): bool
    {
        return $this->enabled() && $this->supportsActiveGame();
    }

    public function setEnabled(bool $enabled): void
    {
        $this->settings->set(self::ENABLED, $enabled);
    }

    public function ignoredVersion(): ?string
    {
        $version = $this->settings->get(self::IGNORED_VERSION);

        return ReleaseVersion::channel($version) === 'stable' ? $version : null;
    }

    public function isIgnored(string $version): bool
    {
        $ignored = $this->ignoredVersion();

        return $ignored !== null && version_compare($version, $ignored, '<=');
    }

    public function dismiss(string $version): void
    {
        Cache::lock('kraft.updates.kraftlink.dismiss', 10)->block(3, function () use ($version): void {
            $this->settings->refresh();
            if (!$this->isIgnored($version)) {
                $this->settings->set(self::IGNORED_VERSION, $version);
            }
        });
    }

    public function fetchLatest(): ?array
    {
        $response = Http::acceptJson()
            ->withHeaders(['X-GitHub-Api-Version' => '2026-03-10'])
            ->connectTimeout(2)
            ->timeout(4)
            ->withOptions([
                'allow_redirects' => false,
                'progress' => function ($total, $downloaded): void {
                    if ($total > 262144 || $downloaded > 262144) {
                        throw new RuntimeException('Oversized KraftLink release metadata.');
                    }
                },
            ])
            ->get(self::API);
        if ($response->notFound()) {
            return null;
        }
        if (!$response->ok() || strlen($response->body()) > 262144) {
            throw new RuntimeException('KraftLink release metadata is unavailable.');
        }

        $release = $response->json();
        $tag = $release['tag_name'] ?? null;
        $version = is_string($tag) && str_starts_with($tag, 'v') ? substr($tag, 1) : null;
        if (
            ReleaseVersion::channel($version) !== 'stable' ||
            ($release['draft'] ?? null) !== false ||
            ($release['prerelease'] ?? null) !== false ||
            empty($release['published_at']) ||
            !is_array($release['assets'] ?? null) ||
            !array_any($release['assets'], fn ($asset) => is_array($asset) &&
                ($asset['name'] ?? null) === 'KraftLink.jar' &&
                ($asset['state'] ?? null) === 'uploaded')
        ) {
            throw new RuntimeException('Invalid KraftLink release metadata.');
        }

        return ['version' => $version, 'url' => self::RELEASES.'/tag/'.$tag];
    }
}
