<?php

namespace App\Kraft\Migration;

use App\Kraft\Migration\Contracts\SourceAdapter;
use RuntimeException;

final class SourceAdapters
{
    public function has(string $name): bool
    {
        $class = config('imports.adapters.'.$name);

        return is_string($class) && is_a($class, SourceAdapter::class, true);
    }

    public function get(string $name): SourceAdapter
    {
        if (!$this->has($name)) {
            throw new RuntimeException('Unsupported source CMS.');
        }

        return app(config('imports.adapters.'.$name));
    }
}
