<?php

use App\Http\Middleware\TenantContext;
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
    $middleware->alias([
        'auth.hybrid' => \App\Http\Middleware\AuthenticateSessionOrToken::class,
        'tenant.context' => \App\Http\Middleware\TenantContext::class,
        'tenant.access' => \App\Http\Middleware\EnsureTenantAccess::class,
        'perm' => \App\Http\Middleware\RequirePermission::class,
    ]);
})
    ->withExceptions(function (Exceptions $exceptions): void {
        //
    })->create();
