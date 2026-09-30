<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class PlatformAccount extends Model
{
    protected $fillable = ['platform', 'name', 'external_id', 'credentials', 'token_expires_at', 'meta'];

    protected $hidden = ['credentials'];

    protected function casts(): array
    {
        return [
            'credentials' => 'encrypted:array',
            'token_expires_at' => 'datetime',
            'meta' => 'array',
        ];
    }

    public function channels(): HasMany
    {
        return $this->hasMany(Channel::class);
    }

    public function token(): ?string
    {
        return $this->credentials['access_token'] ?? null;
    }
}
