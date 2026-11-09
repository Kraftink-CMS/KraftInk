<?php

namespace App\Kraft\Migration;

use App\Kraft\Html;
use Dom\HTMLDocument;

final class ImportedHtml
{
    public static function clean(string $html): string
    {
        $cleaned = Html::clean($html);
        if ($cleaned === '') {
            return '';
        }
        $document = HTMLDocument::createFromString(
            '<!DOCTYPE html><html><head></head><body>'.$cleaned.'</body></html>',
            LIBXML_NOERROR,
            'UTF-8',
        );
        $hosts = self::destinationHosts();
        foreach ($document->getElementsByTagName('*') as $element) {
            $element->removeAttribute('srcset');
            $element->removeAttribute('style');
            foreach (['href', 'src', 'poster', 'data'] as $attribute) {
                if (
                    $element->hasAttribute($attribute) &&
                    !self::keep($element->getAttribute($attribute), $attribute === 'href', $hosts)
                ) {
                    $element->removeAttribute($attribute);
                }
            }
        }
        $result = '';
        foreach ($document->getElementsByTagName('body')->item(0)->childNodes as $node) {
            $result .= $document->saveHtml($node);
        }

        return $result;
    }

    private static function keep(string $value, bool $anchor, array $hosts): bool
    {
        if (preg_match('/[\\\\\x00-\x20\x7f]/', $value)) {
            return false;
        }
        if ($anchor && str_starts_with($value, '#')) {
            return !preg_match('/[<>"\']/', $value);
        }
        if (!$hosts || !preg_match('~^https?://~i', $value)) {
            return false;
        }
        $parts = parse_url($value);
        if (
            !is_array($parts) ||
            isset($parts['user']) ||
            isset($parts['pass']) ||
            !($host = self::host($parts['host'] ?? ''))
        ) {
            return false;
        }

        return !in_array($host, $hosts, true);
    }

    private static function destinationHosts(): array
    {
        $hosts = [];
        $configured = parse_url((string) config('app.url', ''), PHP_URL_HOST);
        if (is_string($configured) && $configured !== '') {
            $host = self::host($configured);
            if ($host === null) {
                return [];
            }
            $hosts[] = $host;
        }
        if (app()->bound('request') && ($host = self::host(request()->getHost()))) {
            $hosts[] = $host;
        }

        return array_values(array_unique($hosts));
    }

    private static function host(string $value): ?string
    {
        $value = strtolower(rtrim(trim($value, '[]'), '.'));
        if ($value === '' || preg_match('/[\\\\\x00-\x20\x7f]/', $value)) {
            return null;
        }
        if (filter_var($value, FILTER_VALIDATE_IP)) {
            $packed = inet_pton($value);
            if (strlen($packed) === 16 && substr($packed, 0, 12) === str_repeat("\0", 10)."\xff\xff") {
                return inet_ntop(substr($packed, 12));
            }

            return inet_ntop($packed);
        }
        if (preg_match('/[^\x00-\x7f]/', $value)) {
            if (!function_exists('idn_to_ascii')) {
                return null;
            }
            $value = idn_to_ascii($value, IDNA_DEFAULT, INTL_IDNA_VARIANT_UTS46);
            if ($value === false) {
                return null;
            }
            $value = strtolower(rtrim($value, '.'));
        }
        if (
            !preg_match(
                '/^[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?(?:\.[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?)*$/D',
                $value,
            ) ||
            preg_match('/(?:^|\.)(?:[0-9]+|0x[0-9a-f]+)$/iD', $value)
        ) {
            return null;
        }

        return $value;
    }
}
