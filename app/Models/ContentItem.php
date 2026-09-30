<?php

namespace App\Models;

use App\Enums\ContentStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ContentItem extends Model
{
    protected $fillable = [
        'channel_id', 'trend_id', 'status', 'topic', 'category', 'research', 'storyboard', 'caption', 'sources', 'credits',
        'video_path', 'thumb_path', 'seconds', 'voice_engine', 'scheduled_for', 'published_at', 'dry_run',
        'external_id', 'external_url', 'metrics', 'metrics_at', 'error', 'attempts',
    ];

    protected function casts(): array
    {
        return [
            'status' => ContentStatus::class,
            'research' => 'array',
            'storyboard' => 'array',
            'sources' => 'array',
            'credits' => 'array',
            'metrics' => 'array',
            'scheduled_for' => 'datetime',
            'published_at' => 'datetime',
            'metrics_at' => 'datetime',
            'dry_run' => 'boolean',
        ];
    }

    public function channel(): BelongsTo
    {
        return $this->belongsTo(Channel::class);
    }

    public function trend(): BelongsTo
    {
        return $this->belongsTo(Trend::class);
    }

    /** Working folder for this video: research images, storyboard, renders. */
    public function workDir(string $path = ''): string
    {
        return $this->channel->storagePath('items/'.$this->id.($path ? '/'.ltrim($path, '/') : ''));
    }

    public function views(): ?int
    {
        return isset($this->metrics['views']) ? (int) $this->metrics['views'] : null;
    }
}
