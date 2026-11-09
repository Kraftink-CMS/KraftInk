<?php

declare(strict_types=1);

use Symfony\Component\Process\ExecutableFinder;
use Symfony\Component\Process\Process;

$root = dirname(__DIR__, 2);
require $root.'/vendor/autoload.php';
require $root.'/updater/files.php';
require $root.'/updater/builtins.php';
require __DIR__.'/configuration.php';
KraftReleaseConfiguration::updates($root);
$options = getopt('', ['composer:', 'output:', 'version:', 'public-key:', 'commit:']);
$configuration = KraftManifest::read($root, 'cms', 'kraftink');
$builtins = KraftBuiltinPackages::inventory($configuration['bundled'] ?? []);
$builtinManifests = KraftBuiltinPackages::manifests($root, $builtins);
foreach ($builtinManifests as $manifest) {
    try {
        new App\Kraft\Extensions\Manifest()->read($manifest['path'], $manifest['type'], $manifest['id']);
    } catch (Throwable $error) {
        throw new RuntimeException('A declared builtin package is invalid: '.$manifest['scope'], previous: $error);
    }
}
$version = KraftReleaseConfiguration::version($options['version'] ?? $configuration['version']);
$publicKey = KraftReleaseConfiguration::signingKey($root);
if (isset($options['public-key']) && !hash_equals($publicKey, KraftReleaseConfiguration::publicKey($options['public-key']))) {
    throw new RuntimeException('The requested public key does not match the source signing key registry.');
}
$build = isset($options['commit']) ? ['version' => $version, 'sha' => $options['commit']] : null;
$composer = $options['composer'] ?? new ExecutableFinder()->find('composer');
if (!is_string($composer) || !is_file($composer)) {
    throw new RuntimeException('Provide --composer=/path/to/composer.phar or install Composer in PATH.');
}
$destination = $options['output'] ?? $root.'/dist';
if (!is_dir($destination) && !mkdir($destination, 0755, true)) {
    throw new RuntimeException('Cannot create the output directory.');
}
$destination = realpath($destination);
if (
    KraftReleaseConfiguration::within($destination, $root) &&
    !KraftReleaseConfiguration::within($destination, $root.'/dist')
) {
    throw new RuntimeException('The output directory must be outside packaged sources or inside dist.');
}
$archive = $destination.'/KraftInk-'.$version.'.zip';
if (file_exists($archive) || file_exists($archive.'.sha256')) {
    throw new RuntimeException('The distribution already exists. Choose a different output directory.');
}
$stage = $root.'/.work/distribution-'.gmdate('Ymd-His').'-'.bin2hex(random_bytes(6));
if (!mkdir($stage, 0700, true)) {
    throw new RuntimeException('Cannot create the distribution stage.');
}
$copy = function (string $source, string $target, string $relative = '') use (&$copy, $builtins): void {
    if (!KraftReleaseConfiguration::distributable($relative, $builtins)) {
        return;
    }
    if (is_link($source)) {
        throw new RuntimeException('Distribution sources must not contain symbolic links.');
    }
    if (is_dir($source)) {
        if (!is_dir($target) && !mkdir($target, 0755, true)) {
            throw new RuntimeException('Cannot create a distribution directory.');
        }
        foreach (new DirectoryIterator($source) as $entry) {
            if (!$entry->isDot()) {
                $copy(
                    $entry->getPathname(),
                    $target.'/'.$entry->getFilename(),
                    ltrim($relative.'/'.$entry->getFilename(), '/'),
                );
            }
        }

        return;
    }
    KraftUpdateFiles::validate($relative, null, $builtins);
    if (!is_file($source) || !copy($source, $target)) {
        throw new RuntimeException('Cannot copy a distribution source.');
    }
};
foreach (new DirectoryIterator($root) as $entry) {
    if (!$entry->isDot() && $entry->getFilename() !== 'vendor') {
        $path = $entry->getFilename();
        $copy($entry->getPathname(), $stage.'/'.$path, $path);
    }
}
foreach (['manifest.json', 'artisan', 'composer.json', 'composer.lock', '.env.example'] as $path) {
    if (!is_file($stage.'/'.$path)) {
        throw new RuntimeException('A required distribution source is missing or unsafe.');
    }
}
$configurationSource = file_get_contents($stage.'/manifest.json');
if (
    $configurationSource === false ||
    file_put_contents(
        $stage.'/manifest.json',
        KraftReleaseConfiguration::inject($configurationSource, $version, $build),
    ) === false
) {
    throw new RuntimeException('Cannot prepare release configuration.');
}
foreach (KraftReleaseConfiguration::INSTALLATION_DIRECTORIES as $path) {
    if (!is_dir($stage.'/'.$path) && !mkdir($stage.'/'.$path, 0755, true)) {
        throw new RuntimeException('Cannot prepare a runtime directory.');
    }
    file_put_contents($stage.'/'.$path.'/.gitignore', "*\n!.gitignore\n");
}
$command = str_ends_with(strtolower($composer), '.phar') ? [PHP_BINARY, $composer] : [$composer];
$process = new Process(
    [
        ...$command,
        'install',
        '--no-dev',
        '--prefer-dist',
        '--no-interaction',
        '--no-scripts',
        '--no-plugins',
        '--optimize-autoloader',
    ],
    $stage,
    ['COMPOSER_DEV_MODE' => '0'],
);
$process->setTimeout(600);
$process->mustRun();
$installed = json_decode(file_get_contents($stage.'/vendor/composer/installed.json'), true, 512, JSON_THROW_ON_ERROR);
if (
    ($installed['dev'] ?? true) !== false ||
    !is_file($stage.'/public/build/manifest.json') ||
    !is_file($stage.'/public/vendor/tinymce/tinymce.min.js')
) {
    throw new RuntimeException(
        'The distribution is not ready: production dependencies or compiled assets are missing.',
    );
}
$zip = new ZipArchive();
if ($zip->open($archive, ZipArchive::CREATE | ZipArchive::EXCL) !== true) {
    throw new RuntimeException('Cannot create the distribution archive.');
}
try {
    $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($stage, FilesystemIterator::SKIP_DOTS));
    foreach ($iterator as $entry) {
        $relative = str_replace('\\', '/', substr($entry->getPathname(), strlen($stage) + 1));
        if ($entry->isLink()) {
            throw new RuntimeException('Symbolic links are forbidden in distributions.');
        }
        $placeholder =
            basename($relative) === '.gitignore' &&
            in_array(dirname($relative), KraftReleaseConfiguration::INSTALLATION_DIRECTORIES, true);
        if ($entry->isDir() || (!$placeholder && !KraftReleaseConfiguration::distributable($relative, $builtins))) {
            continue;
        }
        if (!$placeholder) {
            KraftUpdateFiles::validate($relative, null, $builtins);
        }
        $added = $zip->addFile($entry->getPathname(), $relative);
        if (!$added) {
            throw new RuntimeException('Cannot add a distribution file.');
        }
    }
} finally {
    if (!$zip->close()) {
        throw new RuntimeException('Cannot finalize the distribution archive.');
    }
}
$hash = hash_file('sha256', $archive);
file_put_contents($archive.'.sha256', $hash.'  '.basename($archive)."\n");
fwrite(
    STDOUT,
    json_encode(
        ['source' => $stage, 'archive' => $archive, 'sha256' => $hash, 'version' => $version],
        JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR,
    )."\n",
);
