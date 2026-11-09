<?php

declare(strict_types=1);

use Composer\Semver\VersionParser;

$root = dirname(__DIR__, 2);
require $root.'/vendor/autoload.php';
require $root.'/updater/files.php';
require $root.'/updater/builtins.php';
require __DIR__.'/configuration.php';
$options = getopt('', ['source:', 'output:', 'key-file:', 'version:', 'delete:']);
foreach (['source', 'output', 'key-file', 'version'] as $field) {
    if (!isset($options[$field]) || !is_string($options[$field])) {
        throw new RuntimeException(
            'Required arguments: --source --output --key-file --version; optional --delete=deletions.json.',
        );
    }
}
$source = realpath($options['source']);
$version = KraftReleaseConfiguration::version($options['version'], true);
$channel = KraftReleaseConfiguration::channel($version);
if (!$source || !is_dir($source) || is_link($options['source'])) {
    throw new RuntimeException('Use a built CMS directory and a stable, alpha.N or beta.N Semver version.');
}
$configuration = KraftManifest::read($source, 'cms', 'kraftink');
$updateConfiguration = KraftReleaseConfiguration::updates($source);
$builtins = KraftBuiltinPackages::inventory($configuration['bundled'] ?? []);
$preserve = KraftUpdateFiles::preservation(
    array_key_exists('preserve', $updateConfiguration) ? $updateConfiguration['preserve'] : [],
    ['manifest.json', ...array_map(fn ($scope) => $scope.'/manifest.json', KraftBuiltinPackages::scopes($builtins))],
);
$sync = KraftUpdateFiles::synchronization(
    array_key_exists('sync', $updateConfiguration) ? $updateConfiguration['sync'] : [],
);
KraftBuiltinPackages::manifests($source, $builtins);
if ($configuration['version'] !== $version) {
    throw new RuntimeException('The CMS version must match the signed release version.');
}
foreach (['php', 'cms'] as $requirement) {
    new VersionParser()->parseConstraints($configuration['requires'][$requirement]);
}
$installed = json_decode(
    file_get_contents($source.'/vendor/composer/installed.json'),
    true,
    512,
    JSON_THROW_ON_ERROR,
);
if (($installed['dev'] ?? true) !== false || !is_file($source.'/public/build/manifest.json')) {
    throw new RuntimeException('Use production Composer dependencies and compiled frontend assets.');
}
$keyPath = realpath($options['key-file']);
if (
    !$keyPath ||
    !is_file($keyPath) ||
    is_link($options['key-file']) ||
    KraftReleaseConfiguration::within($keyPath, $source) ||
    filesize($keyPath) > 256 ||
    (PHP_OS_FAMILY !== 'Windows' && (fileperms($keyPath) & 0077) !== 0)
) {
    throw new RuntimeException('Use a private regular key file outside the CMS source with mode 600.');
}
$key = base64_decode(trim(file_get_contents($keyPath)), true);
if (!$key || strlen($key) !== SODIUM_CRYPTO_SIGN_SECRETKEYBYTES) {
    throw new RuntimeException('The private key file must contain a base64-encoded Ed25519 secret key.');
}
if (
    !hash_equals(
        KraftReleaseConfiguration::signingKey($source),
        base64_encode(sodium_crypto_sign_publickey_from_secretkey($key)),
    )
) {
    sodium_memzero($key);
    throw new RuntimeException('The signing key does not match the distribution signing key registry.');
}
$output = $options['output'];
if (!is_dir($output) && !mkdir($output, 0755, true)) {
    throw new RuntimeException('Cannot create the release output directory.');
}
$output = realpath($output);
if (KraftReleaseConfiguration::within($output, $source)) {
    sodium_memzero($key);
    throw new RuntimeException('The release output must be outside the CMS source.');
}
$name = 'kraftink-update-'.$version.'.zip';
foreach ([$name, 'kraftink-release.json', 'kraftink-release.sig'] as $file) {
    if (file_exists($output.'/'.$file)) {
        throw new RuntimeException('Release files already exist. Use a new output directory.');
    }
}
$files = [];
$iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($source, FilesystemIterator::SKIP_DOTS));
foreach ($iterator as $entry) {
    $path = str_replace('\\', '/', substr($entry->getPathname(), strlen($source) + 1));
    if ($entry->isLink() || !is_file($entry->getPathname())) {
        throw new RuntimeException('Release files must be regular files.');
    }
    if (!KraftReleaseConfiguration::distributable($path, $builtins)) {
        continue;
    }
    KraftUpdateFiles::validate($path, null, $builtins);
    $files[$path] = hash_file('sha256', $entry->getPathname());
}
ksort($files);
$delete = isset($options['delete'])
    ? json_decode(file_get_contents($options['delete']), true, 32, JSON_THROW_ON_ERROR)
    : [];
if (!is_array($delete) || !array_is_list($delete)) {
    throw new RuntimeException('The deletion file must contain a JSON list of relative file paths.');
}
$unique = [];
foreach (array_merge(array_keys($files), $delete) as $path) {
    if (!is_string($path) || !KraftReleaseConfiguration::distributable($path, $builtins)) {
        throw new RuntimeException('A release contains an excluded or invalid path.');
    }
    KraftUpdateFiles::validate($path, null, $builtins);
    if (isset($unique[strtolower($path)])) {
        throw new RuntimeException('A release has conflicting or case-ambiguous paths.');
    }
    $unique[strtolower($path)] = true;
}
$requirements = $configuration['requires'];
$build = KraftReleaseBuild::installed($configuration);
$manifest = json_encode(
    [
        'schema_version' => 1,
        'version' => $version,
        'channel' => $channel,
        'requirements' => $requirements,
        ...$build === null ? [] : ['build' => $build],
        'builtins' => $builtins,
        'preserve' => $preserve,
        'sync' => $sync,
        'files' => $files,
        'delete' => $delete,
    ],
    JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR,
);
$zip = new ZipArchive();
if ($zip->open($output.'/'.$name, ZipArchive::CREATE | ZipArchive::EXCL) !== true) {
    throw new RuntimeException('Cannot create the signed update archive.');
}
try {
    if (
        !$zip->addFromString('release.json', $manifest) ||
        !$zip->addFromString('release.sig', base64_encode(sodium_crypto_sign_detached($manifest, $key)))
    ) {
        throw new RuntimeException('Cannot add the signed release manifest.');
    }
    foreach ($files as $path => $hash) {
        if (
            !hash_equals($hash, hash_file('sha256', $source.'/'.$path)) ||
            !$zip->addFile($source.'/'.$path, 'files/'.$path)
        ) {
            throw new RuntimeException('Release sources changed during signing.');
        }
    }
} finally {
    if (!$zip->close()) {
        throw new RuntimeException('Cannot finalize the signed update archive.');
    }
}
$metadata = json_encode(
    [
        'schema_version' => 1,
        'version' => $version,
        'channel' => $channel,
        'requirements' => $requirements,
        ...$build === null ? [] : ['build' => $build],
        'archive' => [
            'name' => $name,
            'size' => filesize($output.'/'.$name),
            'sha256' => hash_file('sha256', $output.'/'.$name),
        ],
    ],
    JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR,
);
file_put_contents($output.'/kraftink-release.json', $metadata);
file_put_contents($output.'/kraftink-release.sig', base64_encode(sodium_crypto_sign_detached($metadata, $key)));
sodium_memzero($key);
fwrite(
    STDOUT,
    json_encode(
        [
            'source' => $source,
            'archive' => $output.'/'.$name,
            'sha256' => hash_file('sha256', $output.'/'.$name),
            'files' => count($files),
            'version' => $version,
        ],
        JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR,
    )."\n",
);
