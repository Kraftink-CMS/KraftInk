<?php

namespace App\Events;

use Closure;
use DateTimeInterface;
use Throwable;
use Illuminate\Support\Facades\Log;

class CollectingSitemapEntries
{
    private array $items = [];

    private array $sources = [];

    public function __construct(public readonly string $locale)
    {
    }

    public function add(string $url, DateTimeInterface|string|null $modified = null): void
    {
        $this->items[] = compact('url', 'modified');
    }

    public function source(Closure $source): void
    {
        $this->sources[] = $source;
    }

    public function entries(): iterable
    {
        yield from $this->items;
        foreach ($this->sources as $source) {
            try {
                yield from $source();
            } catch (Throwable $exception) {
                Log::warning('Sitemap contribution failed', [
                    'exception' => $exception::class,
                ]);
            }
        }
    }
}
