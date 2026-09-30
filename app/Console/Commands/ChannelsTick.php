<?php

namespace App\Console\Commands;

use App\Models\Channel;
use App\Services\Channels\Autopilot;
use Illuminate\Console\Command;
use Throwable;

class ChannelsTick extends Command
{
    protected $signature = 'channels:tick {--channel= : Only this channel id}';

    protected $description = 'Run the autopilot once for every active channel';

    public function handle(Autopilot $autopilot): int
    {
        $channels = Channel::where('active', true)->when($this->option('channel'), fn ($q, $id) => $q->whereKey($id))->get();
        foreach ($channels as $channel) {
            try {
                $r = $autopilot->tick($channel);
                $this->line("{$channel->name}: ".collect($r)->map(fn ($v, $k) => "{$k} {$v}")->implode(', '));
            } catch (Throwable $e) {
                $channel->log('Autopilot error: '.$e->getMessage(), 'error');
                $this->error("{$channel->name}: {$e->getMessage()}");
            }
        }

        return self::SUCCESS;
    }
}
