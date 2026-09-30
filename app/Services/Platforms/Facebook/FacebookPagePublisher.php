<?php

namespace App\Services\Platforms\Facebook;

use App\Models\ContentItem;
use App\Services\Platforms\Publisher;
use Illuminate\Support\Facades\File;
use RuntimeException;

/**
 * Publishes vertical videos to a Facebook Page as Reels (start, upload, finish), and reads their numbers back.
 * Needs a Page token with pages_manage_posts, pages_read_engagement and pages_show_list.
 * Meta allows 30 API-published Reels per Page per 24 hours.
 */
class FacebookPagePublisher implements Publisher
{
    public function __construct(private Graph $graph) {}

    public function publish(ContentItem $item): array
    {
        [$pageId, $token] = $this->page($item);
        $file = $item->workDir($item->video_path);
        if (! File::exists($file)) {
            throw new RuntimeException("Video file is missing: {$file}");
        }

        $start = $this->graph->post("{$pageId}/video_reels", $token, ['upload_phase' => 'start']);
        $videoId = $start['video_id'] ?? throw new RuntimeException('Facebook did not return a video id');

        $upload = $this->graph->client()
            ->withHeaders(['Authorization' => "OAuth {$token}", 'offset' => '0', 'file_size' => (string) filesize($file)])
            ->withBody(File::get($file), 'application/octet-stream')
            ->timeout(600)
            ->post($start['upload_url'] ?? $this->graph->url("video-upload/{$videoId}", 'rupload.facebook.com'));
        $this->graph->check($upload);

        $this->graph->post("{$pageId}/video_reels", $token, [
            'upload_phase' => 'finish',
            'video_id' => $videoId,
            'video_state' => 'PUBLISHED',
            'description' => $item->caption,
            'title' => $item->storyboard['title'] ?? $item->topic,
        ]);

        return ['external_id' => (string) $videoId, 'url' => "https://www.facebook.com/reel/{$videoId}"];
    }

    /** Processing state after publishing: "ready", "processing" or "error" with a message. */
    public function status(ContentItem $item): array
    {
        [, $token] = $this->page($item);

        return $this->graph->get($item->external_id, $token, ['fields' => 'status'])['status'] ?? [];
    }

    public function metrics(ContentItem $item): array
    {
        [, $token] = $this->page($item);
        $out = [];
        try {
            $insights = $this->graph->get("{$item->external_id}/video_insights", $token)['data'] ?? [];
            foreach ($insights as $metric) {
                $value = $metric['values'][0]['value'] ?? null;
                if (is_numeric($value)) {
                    $out[$metric['name']] = (int) $value;
                } elseif (is_array($value)) {
                    $out[$metric['name']] = array_sum(array_filter($value, 'is_numeric'));
                }
            }
        } catch (RuntimeException) {
            // Insights can lag or need read_insights; fall back to public counts below.
        }
        try {
            $post = $this->graph->get($item->external_id, $token, ['fields' => 'views,likes.summary(true).limit(0),comments.summary(true).limit(0)']);
            $out['likes'] = $post['likes']['summary']['total_count'] ?? null;
            $out['comments'] = $post['comments']['summary']['total_count'] ?? null;
            if (isset($post['views'])) {
                $out['video_views'] = (int) $post['views'];
            }
        } catch (RuntimeException) {
        }
        $out['views'] = $out['blue_reels_play_count'] ?? $out['fb_reels_total_plays'] ?? $out['post_video_views'] ?? $out['video_views'] ?? $out['total_video_views'] ?? null;

        return array_filter($out, fn ($v) => $v !== null);
    }

    /** @return array{0: string, 1: string} */
    private function page(ContentItem $item): array
    {
        $account = $item->channel->account;
        if (! $account || $account->platform !== 'facebook' || ! $account->token()) {
            throw new RuntimeException('No Facebook Page is connected to this channel');
        }

        return [$account->external_id, $account->token()];
    }
}
