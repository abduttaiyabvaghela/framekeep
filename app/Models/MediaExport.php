<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MediaExport extends Model
{
    protected $fillable = ['user_id', 'selection', 'status', 'disk', 'path', 'message', 'expires_at'];

    protected $hidden = ['user_id', 'disk', 'path', 'selection'];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    protected function casts(): array
    {
        return ['selection' => 'array', 'expires_at' => 'immutable_datetime'];
    }
}
