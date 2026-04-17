<?php

namespace App\Providers;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

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
        RateLimiter::for('security-api-tokens', fn (Request $request) => $this->jsonLimit('security-api-tokens', $request, 20));
        RateLimiter::for('billing-provider-management', fn (Request $request) => $this->jsonLimit('billing-provider-management', $request, 15));
        RateLimiter::for('billing-outbox-write', fn (Request $request) => $this->jsonLimit('billing-outbox-write', $request, 10));
        RateLimiter::for('team-invitations-accept', function (Request $request) {
            $tokenFingerprint = sha1((string) $request->input('token', 'missing'));

            return Limit::perMinute(5)
                ->by(implode(':', [
                    'team-invitations-accept',
                    $tokenFingerprint,
                    (string) $request->ip(),
                ]))
                ->response(function () {
                    return response()->json([
                        'message' => 'Too many requests for this sensitive operation.',
                    ], 429);
                });
        });
    }

    private function jsonLimit(string $bucket, Request $request, int $perMinute): Limit
    {
        return Limit::perMinute($perMinute)
            ->by(implode(':', [
                $bucket,
                (string) $request->attributes->get('tenant_id', 'public'),
                (string) optional($request->user())->id,
                (string) $request->ip(),
            ]))
            ->response(function () {
                return response()->json([
                    'message' => 'Too many requests for this sensitive operation.',
                ], 429);
            });
    }
}
