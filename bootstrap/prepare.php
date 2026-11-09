<?php

declare(strict_types=1);

use App\Kraft\Setup\InstallationFiles;
use Random\RandomException;

$root = dirname(__DIR__);
require_once $root.'/app/Kraft/Setup/InstallationFiles.php';
$storage =
    getenv('LARAVEL_STORAGE_PATH') ?:
    $_ENV['LARAVEL_STORAGE_PATH'] ?? ($_SERVER['LARAVEL_STORAGE_PATH'] ?? $root.'/storage');
require_once __DIR__.'/operations.php';
$down = @file_get_contents(rtrim($storage, '/\\').'/framework/down', false, null, 0, 4096);
$maintenance = is_string($down) ? json_decode($down, true) : null;
if (
    (PHP_SAPI !== 'cli' || KraftOperationGate::queueCommand()) &&
    is_array($maintenance) &&
    !empty($maintenance['kraft_update'])
) {
    $locale = str_starts_with(strtolower($_SERVER['HTTP_ACCEPT_LANGUAGE'] ?? ''), 'en') ? 'en' : 'fr';
    $messages = require $root.'/lang/'.$locale.'/system.php';
    $message = $messages['maintenance']['default'];
    if (PHP_SAPI === 'cli') {
        fwrite(STDERR, $message.PHP_EOL);
        exit(75);
    }
    http_response_code(503);
    header('Retry-After: 10');
    header('Cache-Control: no-store');
    $json =
        str_contains(strtolower($_SERVER['HTTP_ACCEPT'] ?? ''), 'application/json') ||
        str_starts_with($_SERVER['REQUEST_URI'] ?? '', '/api/');
    header('Content-Type: '.($json ? 'application/json' : 'text/plain').'; charset=UTF-8');
    exit(
        $json
            ? (json_encode(['message' => $message], JSON_INVALID_UTF8_SUBSTITUTE) ?:
            '{"message":"KraftInk"}')
            : $message
    );
}
try {
    KraftOperationGate::start($storage);
} catch (Throwable) {
    $locale = str_starts_with(strtolower($_SERVER['HTTP_ACCEPT_LANGUAGE'] ?? ''), 'en') ? 'en' : 'fr';
    $catalogue = require $root.'/lang/'.$locale.'/system.php';
    $messages = $catalogue['coordination'];
    if (PHP_SAPI === 'cli') {
        fwrite(STDERR, $messages['busy'].PHP_EOL);
        exit(75);
    }
    http_response_code(503);
    header('Retry-After: 5');
    header('Cache-Control: no-store');
    $json =
        str_contains(strtolower($_SERVER['HTTP_ACCEPT'] ?? ''), 'application/json') ||
        str_starts_with($_SERVER['REQUEST_URI'] ?? '', '/api/');
    header('Content-Type: '.($json ? 'application/json' : 'text/plain').'; charset=UTF-8');
    exit(
        $json
            ? (json_encode(['message' => $messages['busy']], JSON_INVALID_UTF8_SUBSTITUTE) ?:
            '{"message":"KraftInk"}')
            : $messages['busy']
    );
}
if (!is_file($root.'/.env')) {
    if (InstallationFiles::installed($storage)) {
        $locale = str_starts_with(strtolower($_SERVER['HTTP_ACCEPT_LANGUAGE'] ?? ''), 'en') ? 'en' : 'fr';
        $messages = require $root.'/lang/'.$locale.'/system.php';
        $message = $messages['install']['messages']['environment_missing'] ?? 'KraftInk';
        if (PHP_SAPI === 'cli') {
            fwrite(STDERR, $message.PHP_EOL);
            exit(75);
        }
        http_response_code(503);
        header('Cache-Control: no-store');
        $json =
            str_contains(strtolower($_SERVER['HTTP_ACCEPT'] ?? ''), 'application/json') ||
            str_starts_with($_SERVER['REQUEST_URI'] ?? '', '/api/');
        header('Content-Type: '.($json ? 'application/json' : 'text/plain').'; charset=UTF-8');
        exit(
            $json
                ? (json_encode(['message' => $message], JSON_INVALID_UTF8_SUBSTITUTE) ?:
                '{"message":"KraftInk"}')
                : $message
        );
    }
    $lock = @fopen(rtrim($storage, '/\\').'/framework/prepare.lock', 'c+');
    if (!$lock || !flock($lock, LOCK_EX)) {
        throw new RuntimeException('Cannot prepare the application environment.');
    }
    try {
        if (!is_file($root.'/.env')) {
            $template = @file_get_contents($root.'/.env.example');
            if ($template === false) {
                throw new RuntimeException('The environment template is missing.');
            }
            $template = preg_replace('/^APP_KEY=.*$/m', 'APP_KEY=base64:'.base64_encode(random_bytes(32)), $template);
            $temporary = $root.'/.env.new-'.bin2hex(random_bytes(8));
            $previousMask = umask(0077);
            try {
                $handle = @fopen($temporary, 'xb');
                if (!$handle) {
                    throw new RuntimeException('Cannot create a private environment file.');
                }
                chmod($temporary, 0600);
                try {
                    if (fwrite($handle, $template) !== strlen($template) || !fflush($handle) || !fsync($handle)) {
                        throw new RuntimeException('Cannot persist the application environment.');
                    }
                } finally {
                    fclose($handle);
                }
                if (!rename($temporary, $root.'/.env')) {
                    throw new RuntimeException('Cannot create the application environment.');
                }
            } finally {
                umask($previousMask);
                if (is_file($temporary)) {
                    unlink($temporary);
                }
            }
        }
    } catch (RandomException $exception) {
        throw new RuntimeException('Cannot securely prepare the application environment.', 0, $exception);
    } finally {
        flock($lock, LOCK_UN);
        fclose($lock);
    }
}
