<?php

declare(strict_types=1);

class KraftSigningKeys
{
    public static function resolve(mixed $reference, array $registry): string
    {
        $encoded = is_string($reference) ? (array_key_exists($reference, $registry) ? $registry[$reference] : $reference) : null;
        $key = is_string($encoded) ? base64_decode($encoded, true) : false;
        if (
            !is_string($reference) || trim($reference) === '' || strlen($reference) > 128 ||
            !is_string($key) || strlen($key) !== 32 || base64_encode($key) !== $encoded
        ) {
            throw new RuntimeException('A registered Ed25519 public key must be configured.');
        }

        return $encoded;
    }

    public static function fromSource(string $source, mixed $id, bool $useEnvironment = false): string
    {
        if (strlen($source) > 65536) {
            throw new RuntimeException('The signing key registry is too large.');
        }
        $tokens = array_values(array_filter(token_get_all($source, TOKEN_PARSE), fn ($token) => !is_array($token) || !in_array($token[0], [T_OPEN_TAG, T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)));
        $position = 0;
        if (($tokens[$position++][0] ?? null) !== T_RETURN) {
            throw new RuntimeException('The signing key registry must return a static array.');
        }
        $settings = self::value($tokens, $position, $useEnvironment);
        self::expect($tokens, $position, ';');
        if ($position !== count($tokens) || !is_array($settings) || !is_array($settings['public_keys'] ?? null)) {
            throw new RuntimeException('The signing key registry must return public_keys.');
        }

        return self::resolve($id, $settings['public_keys']);
    }

    private static function value(array $tokens, int &$position, bool $useEnvironment, int $depth = 0): mixed
    {
        if ($depth > 16) {
            throw new RuntimeException('The signing key registry is too deeply nested.');
        }
        $token = $tokens[$position++] ?? null;
        if ($token === '[') {
            $result = [];
            while (($tokens[$position] ?? null) !== ']') {
                $key = self::value($tokens, $position, $useEnvironment, $depth + 1);
                if (!is_string($key) || array_key_exists($key, $result) || ($tokens[$position++][0] ?? null) !== T_DOUBLE_ARROW) {
                    throw new RuntimeException('The signing key registry must contain unique named entries.');
                }
                $result[$key] = self::value($tokens, $position, $useEnvironment, $depth + 1);
                if (($tokens[$position] ?? null) === ']') {
                    break;
                }
                self::expect($tokens, $position, ',');
            }
            self::expect($tokens, $position, ']');

            return $result;
        }
        if (is_array($token) && $token[0] === T_CONSTANT_ENCAPSED_STRING) {
            $value = substr($token[1], 1, -1);
            if (strpbrk($value, "\\\r\n") !== false) {
                throw new RuntimeException('Use plain strings in the signing key registry.');
            }

            return $value;
        }
        if (is_array($token) && $token[0] === T_STRING && strtolower($token[1]) === 'null') {
            return null;
        }
        if (is_array($token) && $token[0] === T_STRING && $token[1] === 'env') {
            self::expect($tokens, $position, '(');
            $name = self::value($tokens, $position, false, $depth + 1);
            if (!is_string($name) || !preg_match('/^[A-Z][A-Z0-9_]{0,127}$/D', $name)) {
                throw new RuntimeException('The signing key environment name is invalid.');
            }
            $default = null;
            if (($tokens[$position] ?? null) === ',') {
                $position++;
                $default = self::value($tokens, $position, $useEnvironment, $depth + 1);
            }
            self::expect($tokens, $position, ')');
            $environment = $useEnvironment ? getenv($name) : false;
            $value = $environment === false ? $default : $environment;
            if (($tokens[$position] ?? null) === '?') {
                $position++;
                self::expect($tokens, $position, ':');
                $fallback = self::value($tokens, $position, $useEnvironment, $depth + 1);

                return $value ?: $fallback;
            }

            return $value;
        }
        throw new RuntimeException('Use static arrays, strings and env fallbacks in the signing key registry.');
    }

    private static function expect(array $tokens, int &$position, string $expected): void
    {
        if (($tokens[$position++] ?? null) !== $expected) {
            throw new RuntimeException('The signing key registry expression is invalid.');
        }
    }
}
