<?php

namespace App\Media;

use App\Models\MediaItem;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class Usage
{
    public function claimSource(User $user, string $source): void
    {
        if ($user->hasFullAccess()) {
            return;
        }
        $key = ['user_id' => $user->id, 'day' => now('UTC')->toDateString()];
        DB::table('usage_days')->insertOrIgnore($key + ['source' => $source]);
        $claimed = DB::table('usage_days')->where($key)->value('source');
        abort_unless($claimed === $source, 403, 'The free plan allows one source per day.');
    }

    public function claimDownload(User $user, MediaItem $item): void
    {
        if ($user->hasFullAccess()) {
            return;
        }
        DB::transaction(function () use ($user, $item): void {
            User::whereKey($user->id)->lockForUpdate()->firstOrFail();
            $key = ['user_id' => $user->id, 'day' => now('UTC')->toDateString()];
            $query = DB::table('usage_downloads')->where($key);
            if ((clone $query)->where('media_item_id', $item->id)->exists()) {
                return;
            }
            abort_if($query->count() >= config('framekeep.free_downloads'), 403, 'The free daily download limit has been reached.');
            DB::table('usage_downloads')->insert($key + ['media_item_id' => $item->id]);
        }, 3);
    }
}
