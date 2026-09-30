<?php

namespace App\Services\Media;

use App\Models\ContentItem;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use RuntimeException;

/** Renders an item's storyboard to MP4 with the bundled media engine (Node + Chromium + FFmpeg). */
class VideoRenderer
{
    /** @return array{video: string, thumb: ?string, seconds: float, voice_engine: string} paths relative to the item folder */
    public function render(ContentItem $item): array
    {
        $channel = $item->channel;
        $sb = $item->storyboard;
        $dir = $item->workDir();
        $brand = $channel->setting('brand');

        // The media engine expects a "campaign" folder with brand.json; image paths are relative to it.
        File::ensureDirectoryExists($dir.'/ads/'.$sb['id']);
        File::put($dir.'/brand.json', json_encode([
            'name' => $brand['name'],
            'tagline' => $brand['tagline'] ?? null,
            'colors' => $brand['colors'],
            'fonts' => $brand['fonts'],
            'logo' => $brand['logo'] ?? null,
            'tone' => $channel->setting('tone'),
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        $sbFile = $dir.'/ads/'.$sb['id'].'/storyboard.json';
        File::put($sbFile, json_encode($sb, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

        $cfg = config('channels.media');
        $aspect = $sb['aspects'][0];
        $result = Process::timeout($cfg['timeout'])
            ->path($cfg['engine_path'])
            ->env(array_filter(['SVM_CHROMIUM' => $cfg['chromium']]))
            ->run([$cfg['node_bin'], 'scripts/render.mjs', $sbFile, '--aspects', $aspect]);

        if (! $result->successful()) {
            throw new RuntimeException('Render failed: '.mb_substr(trim($result->errorOutput()), -800));
        }
        $out = json_decode($result->output(), true);
        $video = 'ads/'.$sb['id']."/render/{$sb['id']}_{$aspect}.mp4";
        if (! is_array($out) || ! File::exists($dir.'/'.$video)) {
            throw new RuntimeException('Render produced no video: '.mb_substr($result->output().$result->errorOutput(), -500));
        }
        $thumb = 'ads/'.$sb['id'].'/render/thumb.jpg';

        return [
            'video' => $video,
            'thumb' => File::exists($dir.'/'.$thumb) ? $thumb : null,
            'seconds' => (float) ($out['seconds'] ?? 0),
            'voice_engine' => $out['voiceEngine'] ?? 'unknown',
        ];
    }
}
