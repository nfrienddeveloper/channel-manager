<?php

namespace App\Console\Commands;

use App\Models\Channel;
use App\Models\PlatformAccount;
use Illuminate\Console\Command;

class ChannelsCreate extends Command
{
    protected $signature = 'channels:create {name=Trend Brief} {--platform=facebook} {--account= : Platform account id} {--active}';

    protected $description = 'Create a trending-topics channel (starts in dry run)';

    public function handle(): int
    {
        $settings = Channel::defaultSettings();
        $settings['brand']['name'] = $this->argument('name');
        $account = $this->option('account') ? PlatformAccount::find($this->option('account'))
            : PlatformAccount::where('platform', $this->option('platform'))->first();
        $channel = Channel::create([
            'name' => $this->argument('name'),
            'platform' => $this->option('platform'),
            'strategy' => 'trending',
            'active' => (bool) $this->option('active'),
            'live' => false,
            'platform_account_id' => $account?->id,
            'settings' => $settings,
        ]);
        $this->info("Created channel #{$channel->id} \"{$channel->name}\" in dry run".($account ? ", linked to {$account->name}" : '').'.');

        return self::SUCCESS;
    }
}
