<?php

namespace App\Services\Content;

use App\Enums\ContentStatus;
use App\Models\ContentItem;
use App\Services\Content\Writers\ScriptWriter;
use App\Services\Media\VideoRenderer;
use Throwable;

/** Takes a planned item through research, script and render. Each finished step is saved, so a retry resumes. */
class ContentProducer
{
    public const MAX_ATTEMPTS = 3;

    public function __construct(
        private Researcher $researcher,
        private ScriptWriter $writer,
        private ScriptBuilder $builder,
        private VideoRenderer $renderer,
    ) {}

    public function produce(ContentItem $item): ContentItem
    {
        $channel = $item->channel;
        $item->update(['status' => ContentStatus::Producing, 'attempts' => $item->attempts + 1, 'error' => null]);

        try {
            if (! $item->research) {
                $item->update(['research' => $this->researcher->research($item)]);
                $n = count($item->research['notes']);
                $channel->log("Researched \"{$item->topic}\": {$n} sources, ".count($item->research['images']).' photos', item: $item);
            }

            if (! $item->storyboard) {
                $script = $this->writer->write($item, $item->research);
                $built = $this->builder->build($item, $script, $item->research);
                if ($built['skip']) {
                    $item->update(['status' => ContentStatus::Skipped, 'category' => $built['category'], 'error' => $built['skip']]);
                    $channel->log("Skipped \"{$item->topic}\": {$built['skip']}", item: $item);

                    return $item;
                }
                $item->update([
                    'category' => $built['category'],
                    'storyboard' => $built['storyboard'],
                    'caption' => $built['caption'],
                    'sources' => $built['sources'],
                    'credits' => $built['credits'],
                ]);
                $channel->log("Script written for \"{$item->topic}\" ({$built['category']}, ".count($built['storyboard']['scenes']).' scenes)', item: $item);
            }

            if (! $item->video_path) {
                $r = $this->renderer->render($item);
                $item->update(['video_path' => $r['video'], 'thumb_path' => $r['thumb'], 'seconds' => $r['seconds'], 'voice_engine' => $r['voice_engine']]);
                $channel->log(sprintf('Rendered "%s": %.1f s, voice %s', $item->topic, $r['seconds'], $r['voice_engine']), item: $item);
            }

            $item->update(['status' => ContentStatus::Ready, 'attempts' => 0]);
        } catch (Throwable $e) {
            $final = $item->attempts >= self::MAX_ATTEMPTS;
            $item->update(['status' => $final ? ContentStatus::Failed : ContentStatus::Planned, 'error' => mb_substr($e->getMessage(), 0, 2000)]);
            $channel->log(($final ? 'Gave up on' : 'Will retry')." \"{$item->topic}\": {$e->getMessage()}", 'error', $item);
        }

        return $item->refresh();
    }
}
