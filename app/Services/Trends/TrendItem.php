<?php

namespace App\Services\Trends;

/** One trending item as a single source reports it, before merging. */
final class TrendItem
{
    /**
     * @param  array<int, array{title: string, url: string, source?: string}>  $articles
     */
    public function __construct(
        public string $source,
        public string $title,
        public float $weight,
        public int $traffic = 0,
        public ?string $url = null,
        public ?string $summary = null,
        public array $articles = [],
        public ?string $imageUrl = null,
    ) {}
}
