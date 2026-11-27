<?php

namespace App\Http\Controllers;

use App\Media\Platform;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class BillingController
{
    public function catalog(): JsonResponse
    {
        return response()->json([
            'pro' => config('framekeep.offer'),
            'checkout_enabled' => false,
            'platforms' => array_map(fn (Platform $platform) => [
                'id' => $platform->value, 'available' => $platform->ready(),
                'downloads' => $platform !== Platform::YouTube,
                'input' => match ($platform) {
                    Platform::Instagram, Platform::X => 'username',
                    Platform::InstagramPro => 'connected account posts and reels or active stories',
                    Platform::YouTube => 'channel name, ID, or @handle',
                    Platform::Pinterest => 'approved public board ID',
                },
            ], Platform::cases()),
        ])->header('Cache-Control', 'public, max-age=60');
    }

    public function checkout(Request $request): JsonResponse
    {
        $request->validate(['plan' => ['required', 'in:pro-annual']]);

        return response()->json(['message' => 'Payments are disabled.'], 503);
    }
}
