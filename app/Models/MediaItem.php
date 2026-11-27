<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MediaItem extends Model
{
    protected $fillable = ['remote_id', 'type', 'title', 'permalink', 'thumbnail', 'variants', 'published_at'];

    protected function casts(): array
    {
        return ['variants' => 'array', 'published_at' => 'immutable_datetime'];
    }

    public function mediaImport(): BelongsTo
    {
        return $this->belongsTo(MediaImport::class);
    }
}
