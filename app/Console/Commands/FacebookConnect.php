<?php

namespace App\Console\Commands;

use App\Services\Platforms\Facebook\PageConnector;
use Illuminate\Console\Command;
use Throwable;

use function Laravel\Prompts\password;
use function Laravel\Prompts\select;
use function Laravel\Prompts\text;

class FacebookConnect extends Command
{
    protected $signature = 'facebook:connect';

    protected $description = 'Connect a Facebook Page (token typed privately, stored encrypted)';

    public function handle(PageConnector $connector): int
    {
        $this->line('In the Graph API Explorer (developers.facebook.com/tools/explorer), pick your app, add the permissions');
        $this->line('pages_show_list, pages_read_engagement, pages_manage_posts and read_insights, then Generate Access Token.');
        $token = password('Paste the user access token (hidden)', required: true);
        $appId = config('channels.facebook.app_id') ?: text('App ID (from App settings > Basic; blank to skip)');
        $secret = config('channels.facebook.app_secret') ?: ($appId ? password('App secret (hidden)') : null);

        try {
            $pages = $connector->pages($token, $appId ?: null, $secret ?: null);
        } catch (Throwable $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }
        if (! $pages) {
            $this->error('That token cannot post to any Page. Check the permissions and that you chose the Page when approving.');

            return self::FAILURE;
        }
        $id = count($pages) === 1 ? $pages[0]['id'] : select('Which Page?', collect($pages)->pluck('name', 'id')->all());
        $page = collect($pages)->firstWhere('id', $id);
        $account = $connector->store($page, (bool) ($appId && $secret));
        $this->info("Connected \"{$account->name}\".".($appId && $secret ? ' The token does not expire.' : ' Without the app secret this token expires in about an hour.'));

        return self::SUCCESS;
    }
}
