<?php

namespace App\Providers;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        RateLimiter::for('account', fn (Request $request) => [
            Limit::perMinute(15)->by('ip:'.$request->ip()),
            Limit::perMinute(5)->by('account:'.hash('sha256', strtolower((string) $request->input('email'))).'|'.$request->ip()),
        ]);
        RateLimiter::for('api', fn (Request $request) => Limit::perMinute(120)->by($request->user()?->id ?: $request->ip()));
        RateLimiter::for('imports', fn (Request $request) => Limit::perMinute(12)->by($request->user()->id));
        RateLimiter::for('transfers', fn (Request $request) => Limit::perMinute(30)->by($request->user()->id));
        RateLimiter::for('checkout', fn (Request $request) => Limit::perMinute(4)->by($request->user()->id));
    }
}
