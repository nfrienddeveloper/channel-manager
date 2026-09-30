<?php

namespace App\Services\Trends\Sources;

use App\Services\Trends\TrendItem;

interface TrendSource
{
    public function key(): string;

    /** @return array<int, TrendItem> */
    public function fetch(string $region): array;
}
