<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ProSubscription extends Model
{
    protected $fillable = ['user_id', 'paid_until'];

    protected function casts(): array
    {
        return ['paid_until' => 'immutable_datetime'];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
