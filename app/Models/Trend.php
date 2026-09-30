<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Trend extends Model
{
    protected $fillable = ['region', 'fingerprint', 'title', 'summary', 'sources', 'articles', 'image_url', 'traffic', 'score', 'first_seen_at', 'last_seen_at'];

    protected function casts(): array
    {
        return [
            'sources' => 'array',
            'articles' => 'array',
            'first_seen_at' => 'datetime',
            'last_seen_at' => 'datetime',
        ];
    }

    /** All text we know about the story, for keyword filters. */
    public function searchText(): string
    {
        return mb_strtolower($this->title.' '.$this->summary.' '.collect($this->articles)->pluck('title')->implode(' '));
    }
}
