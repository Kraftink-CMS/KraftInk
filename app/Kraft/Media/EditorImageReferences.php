<?php

namespace App\Kraft\Media;

use DOMDocument;
use Illuminate\Support\Str;

class EditorImageReferences
{
    public static function ids(string $html, string $route): array
    {
        $placeholder = '00000000-0000-4000-8000-000000000000';
        $target = parse_url(route($route, $placeholder));
        $prefix = substr($target['path'], 0, -strlen($placeholder));
        $host = $target['host'] ?? null;
        $document = new DOMDocument();
        $previous = libxml_use_internal_errors(true);
        try {
            $document->loadHTML(
                '<?xml encoding="utf-8" ?><body>'.$html.'</body>',
                LIBXML_NONET | LIBXML_HTML_NODEFDTD,
            );
            $ids = [];
            foreach ($document->getElementsByTagName('img') as $image) {
                $address = html_entity_decode($image->getAttribute('src'), ENT_QUOTES | ENT_HTML5, 'UTF-8');
                $source = parse_url($address);
                $path = $source['path'] ?? null;
                $imageHost = $source['host'] ?? null;
                if (
                    is_string($path) &&
                    str_starts_with($path, $prefix) &&
                    ($imageHost === null || $imageHost === $host) &&
                    Str::isUuid($id = substr($path, strlen($prefix)))
                ) {
                    $ids[] = $id;
                }
            }

            return array_values(array_unique($ids));
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }
    }
}
