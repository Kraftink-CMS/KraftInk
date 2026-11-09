<?php

namespace App\Kraft\Link;

use Closure;
use Illuminate\Validation\ValidationException;
use LengthException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Throwable;

class LinkOperation
{
    private const int MAX_RESULT_BYTES = 65_536;

    public static function validName(string $operation): bool
    {
        return strlen($operation) <= 64 &&
            preg_match('/^[a-z][a-z0-9_-]{0,31}(?:\.[a-z][a-z0-9_-]{0,31})*$/D', $operation) === 1;
    }

    public static function validExtensionName(string $operation): bool
    {
        return str_contains($operation, '.') && self::validName($operation);
    }

    public static function run(Closure $handler, bool $bounded = true): array
    {
        try {
            $json = json_encode(self::result($handler), JSON_THROW_ON_ERROR);
            if ($bounded && strlen($json) > self::MAX_RESULT_BYTES) {
                throw new LengthException('Link operation result exceeds the size limit.');
            }

            return (array) json_decode($json, false, flags: JSON_THROW_ON_ERROR);
        } catch (Throwable $error) {
            report($error);

            return self::failure(500);
        }
    }

    public static function failure(int $status, array $data = []): array
    {
        return ['success' => false, 'status' => $status, 'data' => (object) $data];
    }

    private static function result(Closure $handler): array
    {
        try {
            return ['success' => true, 'data' => (object) $handler()];
        } catch (ValidationException $error) {
            return self::failure(422, ['errors' => $error->errors()]);
        } catch (HttpExceptionInterface $error) {
            $status = $error->getStatusCode();

            return self::failure($status >= 400 && $status <= 599 ? $status : 500);
        }
    }
}
