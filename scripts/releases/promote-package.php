<?php

declare(strict_types=1);

require_once __DIR__.'/configuration.php';
require_once dirname(__DIR__, 2).'/updater/files.php';
require_once dirname(__DIR__, 2).'/updater/builtins.php';

class KraftReleasePromotion
{
    private const MAX_ARCHIVE_SIZE = 536870912;

    private const MAX_FILE_SIZE = 268435456;

    private const MAX_EXPANDED_SIZE = 2147483648;

    public static function run(array $options): array
    {
        KraftReleaseConfiguration::updates(dirname(__DIR__, 2));
        foreach (['source', 'source-version', 'version', 'output', 'key-file', 'source-tag', 'source-sha'] as $field) {
            if (!isset($options[$field]) || !is_string($options[$field]) || $options[$field] === '') {
                throw new RuntimeException('Required promotion arguments are missing: '.$field.'.');
            }
        }
        $sourceVersion = KraftReleaseConfiguration::version($options['source-version'], true);
        $version = KraftReleaseConfiguration::version($options['version'], true);
        $sourceChannel = KraftReleaseConfiguration::channel($sourceVersion);
        $channel = KraftReleaseConfiguration::channel($version);
        $core = explode('-', $sourceVersion, 2)[0];
        $targetCore = explode('-', $version, 2)[0];
        if (
            !(
                ($sourceChannel === 'alpha' && in_array($channel, ['beta', 'stable'], true)) ||
                ($sourceChannel === 'beta' && $channel === 'stable')
            ) ||
            ($channel === 'beta' && $targetCore !== $core) ||
            ($channel === 'stable' && version_compare($targetCore, $core, '<')) ||
            version_compare($version, $sourceVersion, '<=') ||
            $options['source-tag'] !== 'v'.$sourceVersion ||
            !preg_match('/^(?:[a-f0-9]{40}|[a-f0-9]{64})$/D', $options['source-sha'])
        ) {
            throw new RuntimeException(
                'Promote an alpha to beta on the same target, or an alpha or beta to stable without lowering its target.',
            );
        }
        $source = realpath($options['source']);
        if ($source === false || !is_dir($source) || is_link($options['source'])) {
            throw new RuntimeException('The promotion source must be a regular release asset directory.');
        }
        $keyPath = realpath($options['key-file']);
        if (
            $keyPath === false ||
            !is_file($keyPath) ||
            is_link($options['key-file']) ||
            KraftReleaseConfiguration::within($keyPath, $source) ||
            filesize($keyPath) > 256 ||
            (PHP_OS_FAMILY !== 'Windows' && (fileperms($keyPath) & 0077) !== 0)
        ) {
            throw new RuntimeException('Use a private regular signing key outside the release assets with mode 600.');
        }
        $key = base64_decode(trim(self::read($keyPath, 256)), true);
        if ($key === false || strlen($key) !== SODIUM_CRYPTO_SIGN_SECRETKEYBYTES) {
            throw new RuntimeException('The private key must be a base64-encoded Ed25519 secret key.');
        }
        try {
            if (!hash_equals(KraftReleaseConfiguration::signingKey(dirname(__DIR__, 2)), base64_encode(sodium_crypto_sign_publickey_from_secretkey($key)))) {
                throw new RuntimeException('The signing key does not match the source signing key registry.');
            }

            return self::promote($options, $source, $sourceVersion, $version, $core, $key);
        } finally {
            sodium_memzero($key);
        }
    }

    private static function promote(
        array $options,
        string $source,
        string $sourceVersion,
        string $version,
        string $core,
        string $key,
    ): array {
        $publicKey = sodium_crypto_sign_publickey_from_secretkey($key);
        $sourceDistribution = 'KraftInk-'.$sourceVersion.'.zip';
        $sourceUpdate = 'kraftink-update-'.$sourceVersion.'.zip';
        $paths = [];
        foreach (
            [
                $sourceDistribution,
                $sourceDistribution.'.sha256',
                $sourceUpdate,
                'kraftink-release.json',
                'kraftink-release.sig',
            ] as $name
        ) {
            $path = $source.'/'.$name;
            if (!is_file($path) || is_link($path) || filesize($path) > self::MAX_ARCHIVE_SIZE) {
                throw new RuntimeException('A source release asset is missing, oversized or unsafe: '.$name.'.');
            }
            $paths[$name] = $path;
        }
        $rawMetadata = self::read($paths['kraftink-release.json'], 262144);
        self::signature($rawMetadata, self::read($paths['kraftink-release.sig'], 1024), $publicKey);
        $metadata = json_decode($rawMetadata, true, 32, JSON_THROW_ON_ERROR);
        self::manifestVersion($metadata, $sourceVersion);
        $build = $metadata['build'] ?? null;
        if (
            !is_array($build) ||
            !is_string($build['version'] ?? null) ||
            KraftReleaseConfiguration::channel($build['version']) !== 'alpha' ||
            explode('-', $build['version'], 2)[0] !== $core ||
            (KraftReleaseConfiguration::channel($sourceVersion) === 'alpha' && $build['version'] !== $sourceVersion) ||
            ($build['sha'] ?? null) !== $options['source-sha']
        ) {
            throw new RuntimeException('The source release build provenance is missing or inconsistent.');
        }
        $archive = $metadata['archive'] ?? null;
        if (
            !is_array($archive) ||
            ($archive['name'] ?? null) !== $sourceUpdate ||
            !is_int($archive['size'] ?? null) ||
            $archive['size'] !== filesize($paths[$sourceUpdate]) ||
            !is_string($archive['sha256'] ?? null) ||
            !preg_match('/^[a-f0-9]{64}$/D', $archive['sha256']) ||
            !hash_equals($archive['sha256'], hash_file('sha256', $paths[$sourceUpdate]))
        ) {
            throw new RuntimeException('The source update archive disagrees with its signed checksum or size.');
        }
        $checksum = self::read($paths[$sourceDistribution.'.sha256'], 1024);
        if (
            !preg_match(
                '/^([a-f0-9]{64})  '.preg_quote($sourceDistribution, '/').'\r?\n?$/D',
                $checksum,
                $matches,
            ) ||
            !hash_equals($matches[1], hash_file('sha256', $paths[$sourceDistribution]))
        ) {
            throw new RuntimeException('The source distribution checksum is invalid.');
        }
        $distribution = new ZipArchive();
        $update = new ZipArchive();
        try {
            $distributionEntries = self::open($distribution, $paths[$sourceDistribution]);
            $updateEntries = self::open($update, $paths[$sourceUpdate]);
            $rawManifest = self::entry($update, 'release.json', 8388608);
            self::signature($rawManifest, self::entry($update, 'release.sig', 1024), $publicKey);
            $manifest = json_decode($rawManifest, true, 64, JSON_THROW_ON_ERROR);
            self::manifestVersion($manifest, $sourceVersion);
            if (
                ($manifest['build'] ?? null) !== $build ||
                ($manifest['requirements'] ?? null) !== ($metadata['requirements'] ?? null)
            ) {
                throw new RuntimeException('The source manifests disagree on build provenance or requirements.');
            }
            $builtins = KraftBuiltinPackages::inventory($manifest['builtins'] ?? []);
            $manifest['preserve'] = KraftUpdateFiles::preservation(
                array_key_exists('preserve', $manifest) ? $manifest['preserve'] : [],
                [
                    'manifest.json',
                    ...array_map(fn ($scope) => $scope.'/manifest.json', KraftBuiltinPackages::scopes($builtins)),
                ],
            );
            $manifest['sync'] = KraftUpdateFiles::synchronization(
                array_key_exists('sync', $manifest) ? $manifest['sync'] : [],
            );
            $files = $manifest['files'] ?? null;
            if (!is_array($files) || $files === [] || array_is_list($files) || count($files) > 50000) {
                throw new RuntimeException('The source signed file inventory is invalid.');
            }
            $seen = [];
            foreach ($files as $path => $hash) {
                if (!is_string($path) || !is_string($hash) || !preg_match('/^[a-f0-9]{64}$/D', $hash)) {
                    throw new RuntimeException('The source signed file inventory is invalid.');
                }
                if (!KraftReleaseConfiguration::distributable($path, $builtins)) {
                    throw new RuntimeException('The source signed inventory contains an excluded file.');
                }
                KraftUpdateFiles::validate($path, null, $builtins);
                if (isset($seen[strtolower($path)])) {
                    throw new RuntimeException('The source manifest has case-ambiguous paths.');
                }
                $seen[strtolower($path)] = true;
                if (
                    !isset($updateEntries['files/'.$path], $distributionEntries[$path]) ||
                    !hash_equals(
                        $hash,
                        self::entryHash($update, 'files/'.$path, $updateEntries['files/'.$path]['size']),
                    ) ||
                    !hash_equals($hash, self::entryHash($distribution, $path, $distributionEntries[$path]['size']))
                ) {
                    throw new RuntimeException(
                        'A tested distribution file disagrees with the signed update: '.$path.'.',
                    );
                }
            }
            $delete = $manifest['delete'] ?? [];
            if (!is_array($delete) || !array_is_list($delete) || count($delete) > 50000) {
                throw new RuntimeException('The source deletion inventory is invalid.');
            }
            foreach ($delete as $path) {
                if (!is_string($path)) {
                    throw new RuntimeException('The source deletion inventory is invalid.');
                }
                if (!KraftReleaseConfiguration::distributable($path, $builtins)) {
                    throw new RuntimeException('The source deletion inventory contains an excluded file.');
                }
                KraftUpdateFiles::validate($path, null, $builtins);
                if (isset($seen[strtolower($path)])) {
                    throw new RuntimeException('The source deletion inventory conflicts with a release path.');
                }
                $seen[strtolower($path)] = true;
            }
            foreach ($updateEntries as $path => $entry) {
                if ($entry['directory'] || in_array($path, ['release.json', 'release.sig'], true)) {
                    continue;
                }
                if (!str_starts_with($path, 'files/') || !isset($files[substr($path, 6)])) {
                    throw new RuntimeException('The source update contains an unsigned file.');
                }
            }
            foreach ($distributionEntries as $path => $entry) {
                if ($entry['directory']) {
                    continue;
                }
                if (!KraftReleaseConfiguration::distributable($path, $builtins)) {
                    if (
                        basename($path) === '.gitignore' &&
                        in_array(dirname($path), KraftReleaseConfiguration::INSTALLATION_DIRECTORIES, true) &&
                        self::entry($distribution, $path, 1024) === "*\n!.gitignore\n"
                    ) {
                        continue;
                    }
                    throw new RuntimeException('The source distribution contains an excluded file.');
                }
                KraftUpdateFiles::validate($path, null, $builtins);
                if (!isset($files[$path])) {
                    throw new RuntimeException('The source distribution contains an unsigned runtime file.');
                }
            }
            if (!isset($files['manifest.json'])) {
                throw new RuntimeException('The source manifest is missing from the signed update.');
            }
            $configuration = self::entry($distribution, 'manifest.json', 262144);
            $package = KraftManifest::decode($configuration, 'cms', 'kraftink');
            $packageKey = isset($files['config/signing.php'])
                ? KraftSigningKeys::fromSource(self::entry($distribution, 'config/signing.php', 65536), $package['update']['signing_key'])
                : KraftSigningKeys::resolve($package['update']['signing_key'], []);
            if (
                ($package['version'] ?? null) !== $sourceVersion ||
                ($package['release'] ?? null) !== $build ||
                $packageKey !== base64_encode($publicKey) ||
                ($package['requires'] ?? null) !== $manifest['requirements'] ||
                KraftBuiltinPackages::inventory($package['bundled'] ?? []) !== $builtins ||
                KraftUpdateFiles::preservation($package['update']['preserve'] ?? [], ['manifest.json']) !==
                    $manifest['preserve'] ||
                KraftUpdateFiles::synchronization(
                    array_key_exists('sync', $package['update']) ? $package['update']['sync'] : [],
                ) !== $manifest['sync']
            ) {
                throw new RuntimeException('The source manifest does not match the signed release metadata.');
            }
            $nextConfiguration = KraftReleaseConfiguration::inject($configuration, $version);
        } finally {
            if ($distribution->status === ZipArchive::ER_OK && $distribution->numFiles > 0) {
                $distribution->close();
            }
            if ($update->status === ZipArchive::ER_OK && $update->numFiles > 0) {
                $update->close();
            }
        }
        $output = self::output($options['output'], $source, $options['key-file']);
        $distributionName = 'KraftInk-'.$version.'.zip';
        $updateName = 'kraftink-update-'.$version.'.zip';
        $created = [];
        try {
            self::copy($paths[$sourceDistribution], $output.'/'.$distributionName, $created);
            self::rewrite($output.'/'.$distributionName, ['manifest.json' => $nextConfiguration]);
            $manifest['version'] = $version;
            $manifest['channel'] = KraftReleaseConfiguration::channel($version);
            $manifest['source'] = ['tag' => $options['source-tag'], 'sha' => $options['source-sha']];
            $manifest['files']['manifest.json'] = hash('sha256', $nextConfiguration);
            ksort($manifest['files']);
            $newManifest = self::json($manifest);
            self::copy($paths[$sourceUpdate], $output.'/'.$updateName, $created);
            self::rewrite($output.'/'.$updateName, [
                'release.json' => $newManifest,
                'release.sig' => base64_encode(sodium_crypto_sign_detached($newManifest, $key)),
                'files/manifest.json' => $nextConfiguration,
            ]);
            $metadata['version'] = $version;
            $metadata['channel'] = KraftReleaseConfiguration::channel($version);
            $metadata['source'] = $manifest['source'];
            $metadata['archive'] = [
                'name' => $updateName,
                'size' => filesize($output.'/'.$updateName),
                'sha256' => hash_file('sha256', $output.'/'.$updateName),
            ];
            $newMetadata = self::json($metadata);
            self::write($output.'/kraftink-release.json', $newMetadata, $created);
            self::write(
                $output.'/kraftink-release.sig',
                base64_encode(sodium_crypto_sign_detached($newMetadata, $key)),
                $created,
            );
            self::write(
                $output.'/'.$distributionName.'.sha256',
                hash_file('sha256', $output.'/'.$distributionName).'  '.$distributionName."\n",
                $created,
            );
        } catch (Throwable $exception) {
            foreach ($created as $path) {
                if (is_file($path) && !is_link($path) && KraftReleaseConfiguration::within($path, $output)) {
                    unlink($path);
                }
            }
            throw $exception;
        }

        return [
            'version' => $version,
            'source_version' => $sourceVersion,
            'source' => $manifest['source'],
            'build' => $build,
            'archive' => $output.'/'.$distributionName,
            'update' => $output.'/'.$updateName,
            'files' => count($files),
        ];
    }

    private static function manifestVersion(mixed $manifest, string $version): void
    {
        if (
            !is_array($manifest) ||
            ($manifest['schema_version'] ?? null) !== 1 ||
            ($manifest['version'] ?? null) !== $version ||
            ($manifest['channel'] ?? null) !== KraftReleaseConfiguration::channel($version) ||
            !is_array($manifest['requirements'] ?? null) ||
            !is_string($manifest['requirements']['php'] ?? null) ||
            !is_string($manifest['requirements']['cms'] ?? null)
        ) {
            throw new RuntimeException('The source signed release metadata is inconsistent.');
        }
    }

    private static function signature(string $raw, string $encoded, string $publicKey): void
    {
        $signature = base64_decode(trim($encoded), true);
        if (
            $signature === false ||
            strlen($signature) !== SODIUM_CRYPTO_SIGN_BYTES ||
            !sodium_crypto_sign_verify_detached($signature, $raw, $publicKey)
        ) {
            throw new RuntimeException('The source release signature or signing key was rejected.');
        }
    }

    private static function open(ZipArchive $zip, string $path): array
    {
        if ($zip->open($path, ZipArchive::RDONLY) !== true || $zip->numFiles < 1 || $zip->numFiles > 50002) {
            throw new RuntimeException('The source archive is invalid or has too many entries.');
        }
        $entries = [];
        $seen = [];
        $directories = [];
        $spellings = [];
        $size = 0;
        for ($index = 0; $index < $zip->numFiles; $index++) {
            $entry = $zip->statIndex($index);
            if (!is_array($entry) || !is_string($entry['name'] ?? null)) {
                throw new RuntimeException('The source archive entry is invalid.');
            }
            $name = $entry['name'];
            $directory = str_ends_with($name, '/');
            $normalized = $directory ? substr($name, 0, -1) : $name;
            if ($normalized === '' || strlen($normalized) > 500) {
                throw new RuntimeException('The source archive path is unsafe.');
            }
            $parents = [];
            $originalParents = [];
            foreach (explode('/', $normalized) as $part) {
                if (
                    !preg_match('/^[A-Za-z0-9_@.-]+$/D', $part) ||
                    in_array($part, ['.', '..'], true) ||
                    str_ends_with($part, '.') ||
                    preg_match('/^(?:CON|PRN|AUX|NUL|COM[1-9]|LPT[1-9])(?:\.|$)/iD', $part)
                ) {
                    throw new RuntimeException('The source archive path is unsafe.');
                }
                $parents[] = strtolower($part);
                $originalParents[] = $part;
                $parent = implode('/', $parents);
                $originalParent = implode('/', $originalParents);
                if (isset($spellings[$parent]) && $spellings[$parent] !== $originalParent) {
                    throw new RuntimeException('The source archive has case-ambiguous directory names.');
                }
                $spellings[$parent] = $originalParent;
                if ($parent !== strtolower($normalized)) {
                    if (isset($seen[$parent]) && !$seen[$parent]) {
                        throw new RuntimeException('A source archive file obstructs a directory.');
                    }
                    $directories[$parent] = true;
                }
            }
            $folded = strtolower($normalized);
            if (array_key_exists($folded, $seen) || (!$directory && isset($directories[$folded]))) {
                throw new RuntimeException('The source archive has duplicate or case-ambiguous paths.');
            }
            $seen[$folded] = $directory;
            $zip->getExternalAttributesIndex($index, $system, $attributes);
            $mode = ($attributes >> 16) & 0170000;
            if (
                !in_array($mode, [0, 0100000, 0040000], true) ||
                ($mode === 0040000 && !$directory) ||
                ($entry['encryption_method'] ?? 0) !== 0 ||
                !is_int($entry['size'] ?? null) ||
                $entry['size'] < 0 ||
                $entry['size'] > self::MAX_FILE_SIZE ||
                ($directory && $entry['size'] !== 0)
            ) {
                throw new RuntimeException('The source archive has a symbolic, encrypted or oversized entry.');
            }
            $size += $entry['size'];
            if ($size > self::MAX_EXPANDED_SIZE) {
                throw new RuntimeException('The expanded source archive is oversized.');
            }
            $entries[$name] = $entry + ['directory' => $directory];
        }

        return $entries;
    }

    private static function entry(ZipArchive $zip, string $name, int $limit): string
    {
        $entry = $zip->statName($name);
        if (!$entry || $entry['size'] > $limit) {
            throw new RuntimeException('A required source archive entry is missing or oversized.');
        }
        $contents = $zip->getFromName($name);
        if (!is_string($contents) || strlen($contents) !== $entry['size']) {
            throw new RuntimeException('A source archive entry is incomplete.');
        }

        return $contents;
    }

    private static function entryHash(ZipArchive $zip, string $name, int $size): string
    {
        $stream = $zip->getStream($name);
        if ($stream === false) {
            throw new RuntimeException('Cannot read a source archive entry.');
        }
        $hash = hash_init('sha256');
        $read = 0;
        try {
            while (!feof($stream)) {
                $chunk = fread($stream, 65536);
                if ($chunk === false || ($chunk === '' && !feof($stream))) {
                    throw new RuntimeException('A source archive entry is incomplete.');
                }
                $read += strlen($chunk);
                if ($read > $size) {
                    throw new RuntimeException('A source archive entry exceeds its declared size.');
                }
                hash_update($hash, $chunk);
            }
        } finally {
            fclose($stream);
        }
        if ($read !== $size) {
            throw new RuntimeException('A source archive entry is incomplete.');
        }

        return hash_final($hash);
    }

    private static function output(string $path, string $source, string $keyPath): string
    {
        if (is_link($path) || (!is_dir($path) && !mkdir($path, 0755, true))) {
            throw new RuntimeException('Cannot create a regular promotion output directory.');
        }
        $output = realpath($path);
        if (
            $output === false ||
            KraftReleaseConfiguration::within($output, $source) ||
            KraftReleaseConfiguration::within(realpath($keyPath), $output) ||
            count(scandir($output)) !== 2
        ) {
            throw new RuntimeException(
                'Use an empty promotion output directory outside the source assets and signing key.',
            );
        }

        return $output;
    }

    private static function rewrite(string $path, array $replacements): void
    {
        $zip = new ZipArchive();
        if ($zip->open($path) !== true) {
            throw new RuntimeException('Cannot open the promoted archive.');
        }
        try {
            foreach ($replacements as $name => $contents) {
                if (
                    !$zip->addFromString($name, $contents) ||
                    !$zip->setMtimeName($name, 315532800) ||
                    !$zip->setCompressionName($name, ZipArchive::CM_DEFLATE, 6) ||
                    !$zip->setExternalAttributesName($name, ZipArchive::OPSYS_UNIX, 0100644 << 16)
                ) {
                    throw new RuntimeException('Cannot update promoted release metadata.');
                }
            }
        } finally {
            if (!$zip->close()) {
                throw new RuntimeException('Cannot finalize a promoted archive.');
            }
        }
    }

    private static function copy(string $source, string $path, array &$created): void
    {
        $input = fopen($source, 'rb');
        $output = fopen($path, 'xb');
        if ($input === false || $output === false) {
            if (is_resource($input)) {
                fclose($input);
            }
            if (is_resource($output)) {
                fclose($output);
            }
            throw new RuntimeException('Cannot copy a release asset without overwriting existing files.');
        }
        $created[] = $path;
        try {
            if (stream_copy_to_stream($input, $output) !== filesize($source)) {
                throw new RuntimeException('Cannot copy a complete release asset.');
            }
        } finally {
            fclose($input);
            fclose($output);
        }
    }

    private static function write(string $path, string $contents, array &$created): void
    {
        $handle = fopen($path, 'xb');
        if ($handle === false) {
            throw new RuntimeException('Cannot create release metadata without overwriting an existing file.');
        }
        $created[] = $path;
        try {
            if (fwrite($handle, $contents) !== strlen($contents)) {
                throw new RuntimeException('Cannot write complete release metadata.');
            }
        } finally {
            fclose($handle);
        }
    }

    private static function read(string $path, int $limit): string
    {
        $contents = file_get_contents($path, false, null, 0, $limit + 1);
        if ($contents === false || strlen($contents) > $limit) {
            throw new RuntimeException('A source release asset is unreadable or oversized.');
        }

        return $contents;
    }

    private static function json(array $value): string
    {
        return json_encode($value, JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR);
    }
}

if (realpath($_SERVER['SCRIPT_FILENAME'] ?? '') === __FILE__) {
    $options = getopt('', [
        'source:',
        'source-version:',
        'version:',
        'output:',
        'key-file:',
        'source-tag:',
        'source-sha:',
    ]);
    fwrite(
        STDOUT,
        json_encode(KraftReleasePromotion::run($options), JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)."\n",
    );
}
