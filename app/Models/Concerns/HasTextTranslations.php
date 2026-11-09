<?php

namespace App\Models\Concerns;

use App\Kraft\TextTranslations;

trait HasTextTranslations
{
    public function displayText(string $field, bool $html = false): string
    {
        /** @var TextTranslations $translations */
        $translations = app(TextTranslations::class);

        return $translations->resolve(
            (string) $this->getAttribute($field),
            $this->translations ?? [],
            $field,
            html: $html,
        );
    }
}
