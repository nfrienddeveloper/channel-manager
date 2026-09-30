<?php

namespace App\Console\Commands;

use App\Models\Trend;
use App\Services\Trends\TrendCollector;
use Illuminate\Console\Command;

class TrendsCollect extends Command
{
    protected $signature = 'trends:collect {--region=US} {--show=15 : How many top trends to list}';

    protected $description = 'Collect trending stories from all sources and list the top ones';

    public function handle(TrendCollector $collector): int
    {
        $region = strtoupper($this->option('region'));
        $r = $collector->collect($region);
        foreach ($r['counts'] as $k => $n) {
            $this->line("{$k}: {$n} items");
        }
        foreach ($r['errors'] as $k => $e) {
            $this->warn("{$k}: {$e}");
        }
        $this->table(['score', 'sources', 'title'], Trend::where('region', $region)->where('last_seen_at', '>=', now()->subHours(6))
            ->orderByDesc('score')->limit((int) $this->option('show'))->get()
            ->map(fn ($t) => [$t->score, implode(',', $t->sources), mb_substr($t->title, 0, 80)]));

        return self::SUCCESS;
    }
}
