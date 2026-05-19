<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class AuthenticateSessionOnly
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user() ?? auth()->user();

        if ($user === null) {
            abort(401, 'Unauthenticated.');
        }

        $request->attributes->set('auth_mode', 'session');
        $request->attributes->remove('api_token_id');
        $request->attributes->remove('api_token_tenant_id');
        app()->forgetInstance('currentApiToken');

        return $next($request);
    }
}
