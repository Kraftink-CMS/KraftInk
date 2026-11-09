<?php

namespace App\Kraft\Setup;

use InvalidArgumentException;

class EnvironmentFile
{
    public function render(string $source, array $values): string
    {
        foreach ($values as $key => $value) {
            if (
                !preg_match('/^[A-Z][A-Z0-9_]*$/D', $key) ||
                (!is_string($value) && !is_bool($value) && !is_int($value)) ||
                (is_string($value) && str_contains($value, "\0"))
            ) {
                throw new InvalidArgumentException('Invalid environment value.');
            }
            $encoded = $this->encode($value);
            $pattern = '/^'.preg_quote($key, '/').'=[^\r\n]*\r?$/m';
            $line = $key.'='.$encoded;
            $source = preg_match($pattern, $source)
                ? preg_replace_callback($pattern, fn () => $line, $source)
                : rtrim($source, "\r\n")."\n".$line."\n";
        }

        return $source;
    }

    private function encode(string|bool|int $value): string
    {
        if (is_bool($value)) {
            return $value ? 'true' : 'false';
        }
        if (is_int($value)) {
            return (string) $value;
        }
        if (
            in_array(
                strtolower($value),
                ['true', '(true)', 'false', '(false)', 'empty', '(empty)', 'null', '(null)'],
                true,
            ) ||
            preg_match('/\A([\'\"])(.*)\1\z/s', $value)
        ) {
            $value = '"'.$value.'"';
        }

        return '"'.
            str_replace(['\\', '"', '$', "\r", "\n", "\t"], ['\\\\', '\\"', '\\$', '\\r', '\\n', '\\t'], $value).
            '"';
    }
}
