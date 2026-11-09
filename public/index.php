<?php

use Illuminate\Http\Request;

define('LARAVEL_START', microtime(true));

require __DIR__.'/../bootstrap/preflight.php';
KraftStartup::check(dirname(__DIR__));
try {
    require __DIR__.'/../bootstrap/prepare.php';
} catch (Throwable $exception) {
    error_log('KraftInk configuration: '.$exception->getMessage());
    KraftStartup::failure(dirname(__DIR__), 'environment');
}

require __DIR__.'/../vendor/autoload.php';

$app = require_once __DIR__.'/../bootstrap/app.php';

if (file_exists($maintenance = $app->storagePath('framework/maintenance.php'))) {
    require $maintenance;
}

$app->handleRequest(Request::capture());
