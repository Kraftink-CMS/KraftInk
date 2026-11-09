<?php

namespace App\Kraft\Migration;

use JsonException;

final class MediaReferences
{
    /**
     * @throws JsonException
     */
    public function inspect(array $row, ImportContext $context, string $table): void
    {
        foreach ($row as $field => $value) {
            if (!is_string($value) || $value === '') {
                continue;
            }
            $references = [];
            if (
                preg_match(
                    '/^(?:image|images|img|img_url|url_img|forum_image|avatar|cover|miniature|favicon|favicon_url|logo|file|filename|attachment|background)$/iD',
                    $field,
                )
            ) {
                $references[] = $value;
            }
            if (stripos($value, '<img') !== false || stripos($value, '<a ') !== false) {
                preg_match_all('~(?:src|href)\s*=\s*["\']([^"\']+)["\']~i', $value, $matches);
                foreach ($matches[1] as $reference) {
                    if (
                        preg_match(
                            '~\.(?:png|jpe?g|webp|gif|avif|svg|pdf|zip|txt)(?:[?#]|$)|/(?:storage|uploads|attachments|media)/~i',
                            $reference,
                        )
                    ) {
                        $references[] = $reference;
                    }
                }
            }
            foreach (array_unique($references) as $reference) {
                $context->report('media', [
                    'table' => $table,
                    'id' => $row['id'] ?? null,
                    'field' => $field,
                    'path' => $this->path($reference),
                    'reason' => 'file_transfer_required',
                ]);
            }
        }
    }

    public function path(string $value): string
    {
        $parts = parse_url($value);
        if ($parts === false) {
            return '';
        }
        $prefix = isset($parts['host']) ? ($parts['scheme'] ?? 'https').'://'.$parts['host'] : '';

        return mb_substr($prefix.($parts['path'] ?? ''), 0, 1500);
    }
}
