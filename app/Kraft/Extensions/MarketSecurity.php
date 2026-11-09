<?php

namespace App\Kraft\Extensions;

use App\Kraft\RemoteFeed;
use Composer\Semver\VersionParser;
use Illuminate\Support\Facades\Cache;
use RuntimeException;
use Throwable;

class MarketSecurity extends RemoteFeed
{
    private array $documents = [];

    public function synchronize(): array
    {
        $results = [];
        foreach (app(Market::class)->catalogues() as $id => $source) {
            if (empty($source['revocations_url'])) {
                continue;
            }
            try {
                $this->accept($id, $this->fetchFeed($source, 'revocations_url'));
                $results[$id] = true;
            } catch (Throwable) {
                $results[$id] = false;
            }
        }

        return $results;
    }

    public function accept(string $marketId, mixed $input): void
    {
        $source = app(Market::class)->catalogues()[$marketId] ?? null;
        if (!$source || empty($source['revocations_url'])) {
            throw new RuntimeException(__('market.security.invalid'));
        }
        $this->acceptFeed($source, 'revocations', $input, function (array $previous, array $document): void {
            foreach ($previous['revocations'] as $revocation) {
                if (!in_array($revocation, $document['revocations'], true)) {
                    throw new RuntimeException(__('market.security.invalid'));
                }
            }
        });
        $this->documents = [];
        Cache::forever('kraft.market.generation', bin2hex(random_bytes(12)));
    }

    public function provenance(array $package, string $manifestHash): array
    {
        $marketId = $package['source']['id'] ?? null;
        $resource = $package['resource'] ?? [];
        if (!is_string($marketId) || !isset(app(Market::class)->catalogues()[$marketId])) {
            throw new RuntimeException(__('market.security.invalid'));
        }
        $provenance = [
            'market_id' => $marketId,
            'resource_id' => $resource['id'] ?? null,
            'slug' => $resource['slug'] ?? null,
            'type' => $resource['type'] ?? null,
            'version' => $package['version']['version'] ?? null,
            'archive_sha256' => $package['version']['download']['sha256'] ?? null,
            'manifest_sha256' => $manifestHash,
        ];

        return $this->validateProvenance(
            $provenance,
            [
                'id' => $resource['slug'] ?? null,
                'type' => $resource['type'] ?? null,
                'version' => $package['version']['version'] ?? null,
            ],
            $manifestHash,
        );
    }

    public function validateProvenance(?array $provenance, array $extension, string $manifestHash): ?array
    {
        if ($provenance === null) {
            return null;
        }
        if (is_string($provenance['archive_sha256'] ?? null)) {
            $provenance['archive_sha256'] = strtolower($provenance['archive_sha256']);
        }
        if (
            !is_string($provenance['market_id'] ?? null) ||
            !preg_match('/^[a-z][a-z0-9-]{0,49}$/D', $provenance['market_id']) ||
            !is_string($provenance['resource_id'] ?? null) ||
            !preg_match('~^[a-z][a-z0-9-]{1,49}/([a-z][a-z0-9-]{1,49})$~D', $provenance['resource_id'], $identity) ||
            ($provenance['slug'] ?? null) !== $identity[1] ||
            ($provenance['slug'] ?? null) !== ($extension['id'] ?? null) ||
            !in_array($provenance['type'] ?? null, ['plugin', 'theme', 'game'], true) ||
            ($provenance['type'] ?? null) !== ($extension['type'] ?? null) ||
            !is_string($provenance['version'] ?? null) ||
            !is_string($extension['version'] ?? null) ||
            !is_string($provenance['archive_sha256'] ?? null) ||
            !preg_match('/^[a-f0-9]{64}$/D', $provenance['archive_sha256']) ||
            !is_string($provenance['manifest_sha256'] ?? null) ||
            !preg_match('/^[a-f0-9]{64}$/D', $manifestHash) ||
            !hash_equals($provenance['manifest_sha256'], $manifestHash)
        ) {
            throw new RuntimeException(__('market.security.invalid'));
        }
        $parser = new VersionParser();
        $version = $parser->normalize($provenance['version']);
        if ($version !== $parser->normalize($extension['version'])) {
            throw new RuntimeException(__('market.security.invalid'));
        }
        $provenance['version'] = $version;

        return $provenance;
    }

    public function revoked(array $extension): bool
    {
        if (!empty($extension['builtin']) || empty($extension['path']) || empty($extension['id'])) {
            return false;
        }
        try {
            $manifest = $extension['path'].'/manifest.json';
            $hash = is_file($manifest) ? hash_file('sha256', $manifest) : false;
            if (!is_string($hash)) {
                return false;
            }
            $operation = $this->operationProvenance($extension, $hash);
            $provenance =
                $operation !== null
                    ? $operation['provenance']
                    : app(InstallationReceipts::class)->all()[$extension['id']]['market_provenance'] ?? null;
            if (!is_array($provenance)) {
                return false;
            }
            $provenance = $this->validateProvenance($provenance, $extension, $hash);

            return $this->matches(
                $provenance['market_id'],
                $provenance['resource_id'],
                $provenance['type'],
                $provenance['version'],
            );
        } catch (Throwable) {
            return false;
        }
    }

    public function assertPackageAllowed(string $marketId, array $resource, string $version): void
    {
        if (!isset(app(Market::class)->catalogues()[$marketId])) {
            throw new RuntimeException(__('market.security.invalid'));
        }
        if ($this->matches($marketId, $resource['id'], $resource['type'], $version)) {
            throw new RuntimeException(__('market.security.revoked'));
        }
    }

    private function matches(string $marketId, string $resource, string $type, string $version): bool
    {
        $version = new VersionParser()->normalize($version);
        $source = app(Market::class)->catalogues()[$marketId] ?? null;
        $cache =
            $marketId.
            '|'.
            ($source === null ? 'missing' : (($source['signing_key'] ?? null) === null ? 'plain' : 'signed'));
        if (!array_key_exists($cache, $this->documents)) {
            try {
                $this->documents[$cache] = $this->storedFeed($marketId, 'revocations', $source);
            } catch (Throwable) {
                $this->documents[$cache] = null;
            }
        }
        foreach ($this->documents[$cache]['revocations'] ?? [] as $revocation) {
            if (
                $revocation['id'] === $resource &&
                $revocation['type'] === $type &&
                (in_array('*', $revocation['versions'], true) || in_array($version, $revocation['versions'], true))
            ) {
                return true;
            }
        }

        return false;
    }

    private function operationProvenance(array $extension, string $hash): ?array
    {
        if (!app()->runningInConsole()) {
            return null;
        }
        $arguments = $_SERVER['argv'] ?? [];
        $run = null;
        $phase = null;
        if (
            ($arguments[1] ?? null) === 'kraft:extension-migrate' &&
            filter_var(getenv('KRAFT_SAFE_MODE'), FILTER_VALIDATE_BOOLEAN)
        ) {
            $run = getenv('KRAFT_EXTENSION_MIGRATION_RUN');
            $phase = 'migrating';
        } elseif (
            ($arguments[1] ?? null) === 'kraft:extension-health' &&
            getenv('KRAFT_PROBE_EXTENSION') === $extension['id']
        ) {
            foreach ($arguments as $argument) {
                if (is_string($argument) && str_starts_with($argument, '--operation-run=')) {
                    $run = substr($argument, strlen('--operation-run='));
                }
            }
            $phase = 'verifying';
        }
        if (!is_string($run) || !preg_match('/^[0-9]{14}-[a-f0-9]{12}$/D', $run)) {
            return null;
        }
        try {
            $journal = storage_path('app/updates/'.$run.'/journal.json');
            for ($path = $journal; $path !== dirname($path); $path = dirname($path)) {
                if (is_link($path)) {
                    return null;
                }
            }
            $state = json_decode(file_get_contents($journal), true, 64, JSON_THROW_ON_ERROR);
            $scope = app(PackagePaths::class)->scope($extension);
            if (
                !is_array($state) ||
                ($state['id'] ?? null) !== $run ||
                ($state['state'] ?? null) !== $phase ||
                ($state['root'] ?? null) !== base_path() ||
                ($state['storage'] ?? null) !== storage_path() ||
                empty($state['database_snapshot']) ||
                !in_array($state['extension']['operation'] ?? null, ['install', 'update'], true) ||
                ($state['extension']['id'] ?? null) !== $extension['id'] ||
                ($state['extension']['path'] ?? null) !== $scope ||
                !is_string($state['manifest']['version'] ?? null) ||
                !version_compare($state['manifest']['version'], $extension['version'], '==') ||
                ($state['manifest']['files'][$scope.'/manifest.json'] ?? null) !== $hash ||
                !array_key_exists('market_provenance', $state['extension'])
            ) {
                return null;
            }
            $provenance = $state['extension']['market_provenance'];
            if ($provenance !== null && !is_array($provenance)) {
                return null;
            }

            return ['provenance' => $this->validateProvenance($provenance, $extension, $hash)];
        } catch (Throwable) {
            return null;
        }
    }

    protected function validateDocument(mixed $document): array
    {
        $document = parent::validateDocument($document);
        if (
            !is_array($document['revocations'] ?? null) ||
            !array_is_list($document['revocations']) ||
            count($document['revocations']) > 10000
        ) {
            throw new RuntimeException(__('market.security.invalid'));
        }
        foreach ($document['revocations'] as &$revocation) {
            if (
                !is_array($revocation) ||
                !is_string($revocation['id'] ?? null) ||
                !preg_match('~^[a-z][a-z0-9-]{1,49}/([a-z][a-z0-9-]{1,49})$~D', $revocation['id'], $identity) ||
                ($revocation['slug'] ?? null) !== $identity[1] ||
                !in_array($revocation['type'] ?? null, ['plugin', 'theme', 'game'], true) ||
                !is_array($revocation['versions'] ?? null) ||
                !array_is_list($revocation['versions']) ||
                count($revocation['versions']) < 1 ||
                count($revocation['versions']) > 1000 ||
                (isset($revocation['reason']) &&
                    (!is_string($revocation['reason']) || mb_strlen($revocation['reason']) > 2000))
            ) {
                throw new RuntimeException(__('market.security.invalid'));
            }
            foreach ($revocation['versions'] as &$version) {
                if (!is_string($version) || strlen($version) > 100) {
                    throw new RuntimeException(__('market.security.invalid'));
                }
                if ($version !== '*') {
                    $version = new VersionParser()->normalize($version);
                }
            }
            unset($version);
        }
        unset($revocation);

        return $document;
    }
}
