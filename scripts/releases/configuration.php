<?php

declare(strict_types=1);

require_once dirname(__DIR__, 2).'/updater/manifest.php';
require_once dirname(__DIR__, 2).'/updater/releases.php';
require_once dirname(__DIR__, 2).'/updater/signing.php';

class KraftReleaseConfiguration
{
    public const INSTALLATION_DIRECTORIES = [
        'bootstrap/cache',
        'database',
        'extensions/bundled/games',
        'extensions/bundled/plugins',
        'extensions/bundled/themes',
        'extensions/installed/games',
        'extensions/installed/plugins',
        'extensions/installed/themes',
        'storage/app/private',
        'storage/framework/cache/data',
        'storage/framework/sessions',
        'storage/framework/views',
        'storage/logs',
    ];

    public static function version(string $version, bool $signed = false): string
    {
        $number = '(?:0|[1-9][0-9]*)';
        $core = $number.'\\.'.$number.'\\.'.$number;
        $identifier = '(?:0|[1-9][0-9]*|[0-9A-Za-z-]*[A-Za-z-][0-9A-Za-z-]*)';
        $development = '-dev(?:\\.'.$identifier.')*(?:\\+[0-9A-Za-z-]+(?:\\.[0-9A-Za-z-]+)*)?';
        $prerelease = '-(?:alpha|beta)\\.[1-9][0-9]*';
        $suffix = $signed ? '(?:'.$prerelease.')?' : '(?:'.$prerelease.'|'.$development.')?';
        if (!preg_match('/^'.$core.$suffix.'$/D', $version)) {
            throw new RuntimeException(
                $signed
                    ? 'A signed update requires a stable, alpha.N or beta.N Semver version.'
                    : 'Use a stable Semver version or an alpha.N, beta.N or dev prerelease.',
            );
        }

        return $version;
    }

    public static function channel(string $version): string
    {
        self::version($version, true);

        return preg_match('/-(alpha|beta)\\.[1-9][0-9]*$/D', $version, $match) ? $match[1] : 'stable';
    }

    public static function publicKey(string $encoded): string
    {
        $key = base64_decode($encoded, true);
        if ($key === false || strlen($key) !== SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES || base64_encode($key) !== $encoded) {
            throw new RuntimeException('The public key must be a canonical base64-encoded Ed25519 public key.');
        }

        return $encoded;
    }

    public static function inject(
        string $source,
        string $version,
        ?array $build = null,
    ): string {
        self::version($version);
        $manifest = KraftManifest::decode($source, 'cms', 'kraftink');
        $manifest['version'] = $version;
        if ($build !== null) {
            KraftReleaseBuild::manifest(['version' => $version, 'build' => $build]);
            $manifest['release'] = $build;
        }
        KraftReleaseBuild::installed($manifest);

        return json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)."\n";
    }

    public static function signingKey(string $root, bool $useEnvironment = false): string
    {
        $reference = KraftManifest::read($root, 'cms', 'kraftink')['update']['signing_key'];
        $path = $root.'/config/signing.php';
        if (!file_exists($path)) {
            return KraftSigningKeys::resolve($reference, []);
        }
        if (!is_file($path) || is_link($path) || filesize($path) > 65536) {
            throw new RuntimeException('Use a regular bounded signing key registry.');
        }

        return KraftSigningKeys::fromSource(file_get_contents($path), $reference, $useEnvironment);
    }

    public static function within(string $path, string $parent): bool
    {
        $normalize = fn ($value) => rtrim(
            str_replace('\\', '/', PHP_OS_FAMILY === 'Windows' ? strtolower($value) : $value),
            '/',
        );
        $path = $normalize($path);
        $parent = $normalize($parent);

        return $path === $parent || str_starts_with($path, $parent.'/');
    }

    public static function updates(string $root): array
    {
        $configuration = KraftManifest::read($root, 'cms', 'kraftink')['update'];
        if (!is_array($configuration) || !is_array($configuration['protected'] ?? null)) {
            throw new RuntimeException('The update configuration must define protected rules.');
        }
        KraftUpdateFiles::configure($configuration['protected']);

        return $configuration;
    }

    public static function distributable(string $path, array $builtins = []): bool
    {
        if (
            KraftUpdateFiles::preserved($path) ||
            preg_match(
                '~^(?:node_modules|tests|scripts|dist|\.github|\.idea|\.vscode|\.nova|\.zed|\.phpunit\.cache)(?:/|$)~i',
                $path,
            ) ||
            preg_match('~(?:^|/)(?:\.gitignore|hot|\.DS_Store|Thumbs\.db)$|\.map$~i', $path) ||
            in_array(
                strtolower($path),
                [
                    '.bladeformatterrc.json',
                    '.bladeignore',
                    '.editorconfig',
                    '.gitattributes',
                    '.npmrc',
                    '.prettierignore',
                    '.prettierrc.json',
                    '.phpunit.result.cache',
                    'package.json',
                    'package-lock.json',
                    'phpunit.xml',
                    'pint.json',
                    'vite.config.js',
                    '_ide_helper.php',
                    'homestead.json',
                    'homestead.yaml',
                    'release-plan.json',
                    'release-build.json',
                    'release-deletions.json',
                ],
                true,
            )
        ) {
            return false;
        }
        if (str_starts_with($path, 'extensions/')) {
            if (
                in_array(
                    $path,
                    [
                        'extensions/bundled',
                        'extensions/bundled/games',
                        'extensions/bundled/plugins',
                        'extensions/bundled/themes',
                    ],
                    true,
                )
            ) {
                return true;
            }
            $scope = KraftBuiltinPackages::scope($path, $builtins);
            if (
                $scope === null ||
                ($path !== $scope && KraftBuiltinPackages::preserved(substr($path, strlen($scope) + 1)))
            ) {
                return false;
            }
        }
        if (!str_starts_with($path, 'vendor/') || !preg_match('/\.(?:md|markdown|mdown)$/iD', $path)) {
            return true;
        }

        return preg_match(
            '~/(?:LICENSE|LICENCE|COPYRIGHT|NOTICE|AUTHORS)(?:[._-][^/]*)?\.(?:md|markdown|mdown)$~iD',
            $path,
        ) === 1;
    }
}
