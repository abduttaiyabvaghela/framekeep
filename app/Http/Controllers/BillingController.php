<?php

namespace App\Http\Controllers;

use App\Media\Platform;
use App\Models\TestCheckout;
use App\Payments\TestGateway;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Validation\Rule;

class BillingController
{
    public function catalog(): JsonResponse
    {
        $gateways = array_values(array_map(fn (TestGateway $gateway) => $gateway->value,
            array_filter(TestGateway::cases(), fn (TestGateway $gateway) => $gateway->ready())));

        return response()->json([
            'pro' => config('framekeep.offer'),
            'checkout_enabled' => $gateways !== [],
            'test_gateways' => $gateways,
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
        $data = $request->validate(['plan' => ['required', 'in:pro-annual'], 'gateway' => ['nullable', Rule::enum(TestGateway::class)]]);
        abort_unless(config('test-payments.enabled'), 503, 'Payments are disabled.');
        $gateway = TestGateway::tryFrom($data['gateway'] ?? '') ?? abort(422, 'Choose a test gateway.');
        abort_unless($gateway->ready(), 503, 'This test gateway is awaiting configuration.');
        $user = $request->user();
        abort_if($user->hasPro(), 409, 'This account already has Pro access.');

        $lock = Cache::lock('test-checkout:'.$user->id.':'.$gateway->value, 30);
        abort_unless($lock->get(), 409, 'A test checkout is already being prepared.');
        try {
            $checkout = TestCheckout::where(['user_id' => $user->id, 'gateway' => $gateway->value])
                ->where('expires_at', '>', now())->latest()->first();
            if (! $checkout || (! $checkout->url && $checkout->expires_at->lt(now()->addMinutes(31)))) {
                $checkout = TestCheckout::create(['user_id' => $user->id, 'gateway' => $gateway->value, 'expires_at' => now()->addMinutes(45)]);
            }
            $url = $checkout->url ?? $gateway->create($checkout, $user);

            return response()->json(['url' => $url, 'gateway' => $gateway->value, 'mode' => 'test'])->header('Cache-Control', 'no-store');
        } finally {
            $lock->release();
        }
    }

    public function webhook(Request $request, string $provider): JsonResponse
    {
        $gateway = TestGateway::tryFrom($provider);
        abort_unless($gateway?->ready(), 404);
        $id = $gateway->eventSubscription($request);
        if ($id !== null) {
            $lock = Cache::lock('test-payment:'.$provider.':'.$id, 30);
            abort_unless($lock->get(), 503);
            try {
                $gateway->reconcile($id);
            } finally {
                $lock->release();
            }
        }

        return response()->json(['received' => true]);
    }
}
