<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ChannelEvent extends Model
{
    public const UPDATED_AT = null;

    protected $fillable = ['channel_id', 'content_item_id', 'level', 'message', 'context'];

    protected function casts(): array
    {
        return ['context' => 'array'];
    }

    public function channel(): BelongsTo
    {
        return $this->belongsTo(Channel::class);
    }

    public function item(): BelongsTo
    {
        return $this->belongsTo(ContentItem::class, 'content_item_id');
    }
}
