<?php

namespace App\Models;

use Illuminate\Foundation\Auth\User as Authenticatable;
use Laravel\Sanctum\HasApiTokens;

class User extends Authenticatable
{
    use HasApiTokens;

    public $timestamps = false;

    protected $fillable = ['email', 'password'];

    protected $hidden = ['password'];

    public function isAdmin(): bool
    {
        return in_array((int) $this->id, config('framekeep.admin_ids'), true);
    }

    public function hasPro(): bool
    {
        return ProSubscription::whereBelongsTo($this)->where('paid_until', '>', now())->exists();
    }

    public function hasFullAccess(): bool
    {
        return $this->isAdmin() || $this->hasPro();
    }
}
