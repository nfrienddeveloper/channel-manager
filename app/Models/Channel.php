<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Arr;

class Channel extends Model
{
    public const PLATFORMS = [
        'facebook' => 'Facebook Page',
        'instagram' => 'Instagram',
        'tiktok' => 'TikTok',
        'youtube' => 'YouTube Shorts',
        'x' => 'X',
        'linkedin' => 'LinkedIn',
    ];

    public const STRATEGIES = [
        'trending' => 'Trending topics',
    ];

    protected $fillable = ['name', 'platform', 'strategy', 'active', 'live', 'platform_account_id', 'settings', 'last_tick_at'];

    protected function casts(): array
    {
        return [
            'active' => 'boolean',
            'live' => 'boolean',
            'settings' => 'array',
            'last_tick_at' => 'datetime',
        ];
    }

    /** Settings a new trending channel starts with. Every key can be changed per channel. */
    public static function defaultSettings(): array
    {
        return [
            'region' => 'US',
            'language' => 'en',
            'timezone' => config('channels.timezone'),
            'posts_per_day' => 3,
            'post_times' => ['09:00', '13:00', '19:00'],
            'sources' => ['google_trends', 'google_news', 'reddit', 'wikipedia'],
            'include_keywords' => [],
            'exclude_keywords' => [],
            'avoid' => ['tragedy', 'crime', 'politics', 'health', 'adult'],
            'tone' => 'upbeat, curious and neutral; explains what is happening and why people care',
            'max_seconds' => 35,
            'aspect' => '9x16',
            'hashtags' => ['TrendingNow'],
            'voice' => ['engine' => 'auto', 'voice' => 'Adam', 'fallback' => 'am_michael'],
            'brand' => [
                'name' => 'Trend Brief',
                'tagline' => 'What everyone is talking about, in 30 seconds.',
                'colors' => ['primary' => '#1b2a4a', 'accent' => '#ff5a36', 'dark' => '#0e1424', 'light' => '#f7f5f0'],
                'fonts' => ['heading' => 'Montserrat', 'body' => 'Inter'],
                'cta' => 'Follow for the daily trends',
            ],
        ];
    }

    public function setting(string $key, mixed $default = null): mixed
    {
        return Arr::get($this->settings ?? [], $key, Arr::get(self::defaultSettings(), $key, $default));
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(PlatformAccount::class, 'platform_account_id');
    }

    public function items(): HasMany
    {
        return $this->hasMany(ContentItem::class);
    }

    public function events(): HasMany
    {
        return $this->hasMany(ChannelEvent::class);
    }

    public function log(string $message, string $level = 'info', ?ContentItem $item = null, array $context = []): void
    {
        $this->events()->create([
            'content_item_id' => $item?->id,
            'level' => $level,
            'message' => $message,
            'context' => $context ?: null,
        ]);
    }

    /** A few lines on which published videos did best and worst, fed back into the script writer. */
    public function learningsSummary(): string
    {
        $done = $this->items()->where('dry_run', false)->whereNotNull('metrics')->where('published_at', '>=', now()->subDays(30))->get()
            ->filter(fn (ContentItem $i) => $i->views() !== null)->sortByDesc(fn (ContentItem $i) => $i->views())->values();
        if ($done->count() < 3) {
            return 'Nothing measured yet: this is a new page. Aim for broad, light, curiosity-driven stories.';
        }
        $line = fn (ContentItem $i) => "- \"{$i->topic}\" ({$i->category}): {$i->views()} views";
        $byCategory = $done->groupBy('category')->map(fn ($g) => (int) $g->avg(fn ($i) => $i->views()))->sortDesc()
            ->map(fn ($v, $k) => "{$k} {$v}")->implode(', ');

        return "Best:\n".$done->take(3)->map($line)->implode("\n")
            ."\nWeakest:\n".$done->reverse()->take(3)->map($line)->implode("\n")
            ."\nAverage views by category: {$byCategory}";
    }

    public function platformLabel(): string
    {
        return self::PLATFORMS[$this->platform] ?? $this->platform;
    }

    public function storagePath(string $path = ''): string
    {
        return storage_path('app/channels/'.$this->id.($path ? '/'.ltrim($path, '/') : ''));
    }
}
