<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class TestCheckout extends Model
{
    use HasUuids;

    protected $fillable = ['user_id', 'gateway', 'external_id', 'url', 'expires_at', 'paid_until'];

    protected function casts(): array
    {
        return ['expires_at' => 'immutable_datetime', 'paid_until' => 'immutable_datetime'];
    }
}
