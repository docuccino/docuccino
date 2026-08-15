<?php

namespace App\Providers;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

/**
 * Real-engine fixture: closures in their three shapes — an arrow function, a full closure, and a
 * conditional — for the `trace-closure` mode, which locates a closure by start line exactly as the
 * engine does for a closure route. Nothing boots; these are only ever parsed.
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
        // Laravel 11+ skeleton default: an arrow closure — the InArrowFunctionNode path, one implicit
        // return handed over on a lazy fiber scope.
        RateLimiter::for('api', fn (Request $request) => Limit::perMinute(60)->by($request->user()?->id ?: $request->ip()));

        // A full closure — the ClosureReturnStatementsNode path.
        RateLimiter::for('uploads', function (Request $request) {
            return Limit::perHour(100)->by($request->ip());
        });

        // A conditional: the whole ternary is the one return expression.
        RateLimiter::for('dynamic', fn (Request $request) => $request->user() ? Limit::none() : Limit::perMinute(10));
    }
}
