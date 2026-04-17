<?php

use App\Http\Middleware\AddRequestContext;
use App\Http\Middleware\EnsureIdempotency;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware) {
        $middleware->append(AddRequestContext::class);

        $middleware->alias([
            'auth.hybrid' => \App\Http\Middleware\AuthenticateSessionOrToken::class,
            'auth.session' => \App\Http\Middleware\AuthenticateSessionOnly::class,
            'tenant.context' => \App\Http\Middleware\TenantContext::class,
            'tenant.access' => \App\Http\Middleware\EnsureTenantAccess::class,
            'perm' => \App\Http\Middleware\RequirePermission::class,
            'idempotent' => EnsureIdempotency::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        //
    })->create();
