<?php

use App\Kraft\Setup\InstallationFiles;

class KraftStartup
{
    public static function check(string $root): void
    {
        if (PHP_VERSION_ID < 80401) {
            self::failure($root, 'php', ['PHP '.PHP_VERSION]);
        }
        if (PHP_INT_SIZE < 8) {
            self::failure($root, 'architecture');
        }
        $missing = [];
        foreach (
            [
                'ctype',
                'curl',
                'dom',
                'fileinfo',
                'filter',
                'hash',
                'mbstring',
                'openssl',
                'pdo',
                'session',
                'sodium',
                'tokenizer',
                'xml',
                'zip',
            ] as $extension
        ) {
            if (!extension_loaded($extension)) {
                $missing[] = $extension;
            }
        }
        if ($missing) {
            self::failure($root, 'extensions', $missing);
        }
        if (!is_file($root.'/vendor/autoload.php')) {
            self::failure($root, 'dependencies');
        }
        require_once dirname(__DIR__).'/app/Kraft/Setup/InstallationFiles.php';
        $storage =
            getenv('LARAVEL_STORAGE_PATH') ?:
            $_ENV['LARAVEL_STORAGE_PATH'] ?? ($_SERVER['LARAVEL_STORAGE_PATH'] ?? $root.'/storage');
        if (InstallationFiles::installed($storage) && !is_file($root.'/.env')) {
            self::failure($root, 'environment');
        }
    }

    public static function failure(string $root, string $reason, array $details = []): void
    {
        http_response_code(503);
        header('Content-Type: text/html; charset=UTF-8');
        header('X-Content-Type-Options: nosniff');
        $locale = isset($_GET['locale']) && $_GET['locale'] === 'en' ? 'en' : 'fr';
        $catalogue = require $root.'/lang/'.$locale.'/system.php';
        $messages = $catalogue['startup'];
        $escape = static function ($text): string {
            return htmlspecialchars((string) $text, ENT_QUOTES, 'UTF-8');
        };
        $css = '';
        if (is_file($root.'/public/build/manifest.json')) {
            $manifest = json_decode(file_get_contents($root.'/public/build/manifest.json'), true);
            $style = $manifest['resources/scss/app.scss']['file'] ?? null;
            if (is_string($style) && preg_match('~^assets/[A-Za-z0-9_.-]+\.css$~D', $style)) {
                $css = '<link rel="stylesheet" href="/build/'.$escape($style).'">';
            }
        }
        echo '<!DOCTYPE html><html lang="'.
            $locale.
            '"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><meta name="robots" content="noindex,nofollow"><title>KraftInk · '.
            $escape($messages['title']).
            '</title>'.
            $css.
            '</head><body><main class="container py-5"><img src="/images/logos/logo.svg" width="180" height="60" alt="KraftInk"><h1 class="h3 mt-4">'.
            $escape($messages['title']).
            '</h1><p>'.
            $escape($messages[$reason]).
            '</p>';
        if ($details) {
            echo '<ul>';
            foreach ($details as $detail) {
                echo '<li>'.$escape($detail).'</li>';
            }
            echo '</ul>';
        }
        echo '<p>'.
            $escape($messages['help']).
            '</p><p><a href="https://www.php.net/manual/'.
            $locale.
            '/install.php">'.
            $escape($messages['documentation']).
            '</a></p><nav aria-label="'.
            $escape($messages['language']).
            '"><a href="?locale=fr">Français</a> · <a href="?locale=en">English</a></nav></main></body></html>';
        exit();
    }
}
