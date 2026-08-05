<?php

namespace App\Providers;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

/**
 * Real-engine fixture (Wave D item 4): named rate limiters in their idiomatic shapes, the analysis
 * targets the `trace-rate-limiter` mode folds. Nothing boots — the engine only parses these closures
 * by file+line (from `ReflectionFunction`), exactly as the RateLimit integration does at generation.
 */
class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // Laravel 11+ skeleton default: an arrow closure partitioned by user id / ip. Folds to 60/min.
        RateLimiter::for('api', fn (Request $request) => Limit::perMinute(60)->by($request->user()?->id ?: $request->ip()));

        // A full-closure limiter on a per-hour window — the ClosureReturnStatementsNode path. Folds to 100/hour.
        RateLimiter::for('uploads', function (Request $request) {
            return Limit::perHour(100)->by($request->ip());
        });

        // A conditional limiter (a ternary is not a single Limit call): must stay numberless.
        RateLimiter::for('dynamic', fn (Request $request) => $request->user() ? Limit::none() : Limit::perMinute(10));
    }
}
