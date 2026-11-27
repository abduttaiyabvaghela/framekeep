<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class MediaImport extends Model
{
    protected $fillable = ['user_id', 'platform', 'source', 'status', 'label', 'cursor', 'pages', 'message'];

    protected $hidden = ['user_id', 'cursor'];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function items(): HasMany
    {
        return $this->hasMany(MediaItem::class);
    }
}
