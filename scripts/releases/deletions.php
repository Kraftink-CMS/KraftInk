<?php

declare(strict_types=1);

use Symfony\Component\Process\Process;

$root = dirname(__DIR__, 2);
require $root.'/vendor/autoload.php';
require $root.'/updater/files.php';
require $root.'/updater/builtins.php';
require __DIR__.'/configuration.php';
KraftReleaseConfiguration::updates($root);
$options = getopt('', ['base:', 'head:', 'repo:', 'output:']);
foreach (['head'] as $field) {
    if (!isset($options[$field]) || !is_string($options[$field]) || $options[$field] === '') {
        throw new RuntimeException('Required argument: --head=SHA; optional --base=tag --repo --output.');
    }
}
$repository = realpath($options['repo'] ?? $root);
if (!$repository || !is_dir($repository)) {
    throw new RuntimeException('The release repository is missing.');
}
$builtins = KraftBuiltinPackages::inventory(KraftManifest::read($repository, 'cms', 'kraftink')['bundled'] ?? []);
$commits = [];
foreach (['base', 'head'] as $field) {
    if (!isset($options[$field])) {
        continue;
    }
    $process = new Process([
        'git',
        '-C',
        $repository,
        'rev-parse',
        '--verify',
        '--end-of-options',
        $options[$field].'^{commit}',
    ]);
    $process->setTimeout(30);
    $process->mustRun();
    $commits[$field] = trim($process->getOutput());
    if (!preg_match('/^[0-9a-f]{40,64}$/D', $commits[$field])) {
        throw new RuntimeException('Git did not resolve a commit.');
    }
}
$process = new Process([
    'git',
    '-C',
    $repository,
    'log',
    '-m',
    '--no-ext-diff',
    '--no-textconv',
    '--format=',
    '--name-only',
    '--diff-filter=D',
    '--no-renames',
    '-z',
    $commits['head'],
    '--',
]);
$process->setTimeout(60);
$process->mustRun();
$deletedPaths = $process->getOutput();
$process = new Process(['git', '-C', $repository, 'ls-tree', '-r', '--name-only', '-z', $commits['head'], '--']);
$process->setTimeout(60);
$process->mustRun();
$present = array_fill_keys(explode("\0", $process->getOutput()), true);
$deleted = [];
$seen = [];
foreach (explode("\0", $deletedPaths) as $path) {
    $path = ltrim($path, "\r\n");
    if ($path === '' || isset($present[$path])) {
        continue;
    }
    if (!KraftReleaseConfiguration::distributable($path, $builtins)) {
        continue;
    }
    KraftUpdateFiles::validate($path, null, $builtins);
    if (isset($seen[strtolower($path)])) {
        if ($seen[strtolower($path)] !== $path) {
            throw new RuntimeException('Git contains case-ambiguous deletion paths.');
        }

        continue;
    }
    $seen[strtolower($path)] = $path;
    $deleted[] = $path;
}
sort($deleted, SORT_STRING);
$json = json_encode($deleted, JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR)."\n";
if (!isset($options['output'])) {
    fwrite(STDOUT, $json);

    return;
}
$output = $options['output'];
if (!is_dir(dirname($output)) && !mkdir(dirname($output), 0755, true)) {
    throw new RuntimeException('Cannot create the deletion output directory.');
}
$handle = fopen($output, 'xb');
if ($handle === false) {
    throw new RuntimeException('The deletion output already exists or cannot be created.');
}
try {
    if (fwrite($handle, $json) !== strlen($json)) {
        throw new RuntimeException('Cannot write the deletion list.');
    }
} finally {
    fclose($handle);
}
fwrite(
    STDOUT,
    json_encode(
        [
            'output' => realpath($output),
            'deletions' => count($deleted),
            'base' => $commits['base'] ?? null,
            'head' => $commits['head'],
        ],
        JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR,
    )."\n",
);
