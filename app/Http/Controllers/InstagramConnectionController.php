<?php

namespace App\Http\Controllers;

use App\Media\InstagramProfessional;
use App\Media\Platform;
use App\Media\ProviderException;
use App\Models\InstagramConnection;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class InstagramConnectionController
{
    public function show(Request $request): JsonResponse
    {
        $row = InstagramConnection::whereBelongsTo($request->user())->where('expires_at', '>', now())->first();

        return response()->json(['username' => $row?->username, 'expires_at' => $row?->expires_at?->toAtomString()])
            ->header('Cache-Control', 'no-store');
    }

    public function connect(Request $request): JsonResponse
    {
        abort_unless(Platform::InstagramPro->ready(), 503, 'Instagram professional login is not configured.');
        $state = bin2hex(random_bytes(32));
        $request->session()->put('instagram_oauth', ['state' => $state, 'user_id' => $request->user()->id, 'until' => time() + 600]);
        $query = http_build_query([
            'client_id' => config('framekeep.instagram.app_id'), 'redirect_uri' => $this->redirectUri(),
            'scope' => 'instagram_business_basic', 'response_type' => 'code', 'state' => $state, 'enable_fb_login' => 0,
        ], encoding_type: PHP_QUERY_RFC3986);

        return response()->json(['url' => 'https://www.instagram.com/oauth/authorize?'.$query])->header('Cache-Control', 'no-store');
    }

    public function callback(Request $request, InstagramProfessional $instagram): RedirectResponse
    {
        $state = $request->session()->pull('instagram_oauth');
        $provided = $request->query('state');
        if (! $request->user() || ! $state || $state['user_id'] !== $request->user()->id || $state['until'] < time()
            || ! is_string($provided) || ! hash_equals($state['state'], $provided) || ! Platform::InstagramPro->ready()) {
            return redirect()->route('home', ['instagram' => 'expired'], 303);
        }
        $code = $request->query('code');
        if (! is_string($code) || $code === '' || strlen($code) > 2048) {
            return redirect()->route('home', ['instagram' => 'cancelled'], 303);
        }
        try {
            $connection = $instagram->exchange($code, $this->redirectUri());
        } catch (ProviderException) {
            return redirect()->route('home', ['instagram' => 'failed'], 303);
        }
        InstagramConnection::updateOrCreate(['user_id' => $request->user()->id], $connection);

        return redirect()->route('home', ['instagram' => 'connected'], 303);
    }

    public function disconnect(Request $request): JsonResponse
    {
        $request->session()->forget('instagram_oauth');
        InstagramConnection::whereBelongsTo($request->user())->update(['token' => null, 'expires_at' => null]);

        return response()->json(['disconnected' => true]);
    }

    private function redirectUri(): string
    {
        return rtrim(config('app.url'), '/').route('instagram.callback', absolute: false);
    }
}
