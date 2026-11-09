<?php

declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, private');
header('X-Content-Type-Options: nosniff');
$id = $_GET['run'] ?? '';
$token = $_SERVER['HTTP_X_KRAFT_UPDATE_TOKEN'] ?? '';
if (!is_string($id) || !preg_match('/^[0-9]{14}-[a-f0-9]{12}$/', $id) || !is_string($token)) {
    http_response_code(404);
    exit('{"success":false,"data":{}}');
}
$storage =
    getenv('LARAVEL_STORAGE_PATH') ?:
    $_ENV['LARAVEL_STORAGE_PATH'] ?? ($_SERVER['LARAVEL_STORAGE_PATH'] ?? dirname(__DIR__).'/storage');
$path = rtrim($storage, '/\\').'/app/updates/'.$id.'/journal.json';
if (!is_file($path)) {
    http_response_code(404);
    exit('{"success":false,"data":{}}');
}
$data = json_decode(file_get_contents($path), true);
if (!$data || !hash_equals($data['progress_token_hash'] ?? '', hash('sha256', $token))) {
    http_response_code(404);
    exit('{"success":false,"data":{}}');
}
echo json_encode(
    [
        'success' => true,
        'data' => array_intersect_key($data, array_flip(['id', 'state', 'progress', 'version'])),
    ],
    JSON_THROW_ON_ERROR,
);
