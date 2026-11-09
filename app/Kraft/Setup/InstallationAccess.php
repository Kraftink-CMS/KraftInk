<?php

namespace App\Kraft\Setup;

use JsonException;
use Random\RandomException;
use RuntimeException;

class InstallationAccess
{
    public const string COOKIE = 'kraft_installation';

    public function __construct(private readonly ?string $file = null)
    {
    }

    public function authorize(string $ip, ?string $token, bool $create): ?string
    {
        $address = @inet_pton($ip);
        if ($address === false) {
            return null;
        }
        if (strlen($address) === 16 && substr($address, 0, 12) === str_repeat("\0", 10)."\xff\xff") {
            $address = substr($address, 12);
        }
        $ip = inet_ntop($address);
        $path = $this->file ?? InstallationFiles::access(storage_path());
        InstallationFiles::prepare($path, createDirectory: $create);
        if (!$create && !file_exists($path)) {
            return null;
        }
        $mask = umask(0077);
        try {
            $handle = $create && !file_exists($path) ? @fopen($path, 'x+b') : false;
            $created = is_resource($handle);
            if (!$handle) {
                $handle = @fopen($path, 'r+b');
            }
        } finally {
            umask($mask);
        }
        if (!$handle) {
            if (!$create && !file_exists($path)) {
                return null;
            }
            throw new RuntimeException('Cannot open installation protection.');
        }
        try {
            if (!flock($handle, $created ? LOCK_EX : LOCK_EX | LOCK_NB)) {
                return null;
            }
            if (!@chmod($path, 0600)) {
                throw new RuntimeException('Cannot protect installation access.');
            }
            if ($created) {
                $token = bin2hex(random_bytes(32));
                $state = json_encode(
                    [
                        'schema' => 1,
                        'ip' => $ip,
                        'token_hash' => hash('sha256', $token),
                        'created_at' => gmdate(DATE_ATOM),
                    ],
                    JSON_THROW_ON_ERROR,
                );
                if (fwrite($handle, $state) !== strlen($state) || !fflush($handle) || !fsync($handle)) {
                    throw new RuntimeException('Cannot persist installation protection.');
                }

                return $token;
            }
            $contents = stream_get_contents($handle, 4097);
            $state = is_string($contents) && strlen($contents) <= 4096 ? json_decode($contents, true) : null;
            if (
                !is_array($state) ||
                ($state['schema'] ?? null) !== 1 ||
                ($state['ip'] ?? null) !== $ip ||
                !is_string($state['token_hash'] ?? null) ||
                !preg_match('/^[a-f0-9]{64}$/D', $state['token_hash']) ||
                !is_string($token) ||
                !preg_match('/^[a-f0-9]{64}$/D', $token) ||
                !hash_equals($state['token_hash'], hash('sha256', $token))
            ) {
                return null;
            }

            return $token;
        } catch (RandomException|JsonException $exception) {
            throw new RuntimeException('Cannot prepare installation protection.', 0, $exception);
        } finally {
            flock($handle, LOCK_UN);
            fclose($handle);
        }
    }
}
