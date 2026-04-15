<?php

namespace App\Http\Middleware;

use App\Services\Security\ApiTokenService;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

class AuthenticateSessionOrToken
{
    public function __construct(private readonly ApiTokenService $tokens) {}

    public function handle(Request $request, Closure $next): Response
    {
        app()->forgetInstance('currentApiToken');
        $plainTextToken = $request->bearerToken() ?? $request->header('X-Api-Token');

        if ($plainTextToken !== null && trim($plainTextToken) !== '') {
            $token = $this->tokens->resolveActiveToken($plainTextToken);

            if ($token === null) {
                abort(401, 'Unauthenticated.');
            }

            $user = $this->tokens->userForToken($token);

            if ($user === null) {
                abort(401, 'Unauthenticated.');
            }

            auth()->setUser($user);
            $request->setUserResolver(fn () => $user);
            $request->attributes->set('auth_mode', 'bearer');
            $request->attributes->set('api_token_id', $token->id);
            $request->attributes->set('api_token_tenant_id', $token->tenant_id);
            app()->instance('currentApiToken', $token);
            $this->tokens->touch($token);
            Log::withContext([
                'auth_mode' => 'bearer',
                'user_id' => (int) $user->id,
                'api_token_id' => (int) $token->id,
            ]);

            return $next($request);
        }

        $user = $request->user() ?? auth()->user();

        if ($user !== null) {
            $request->attributes->set('auth_mode', 'session');
            $request->attributes->remove('api_token_id');
            $request->attributes->remove('api_token_tenant_id');
            Log::withContext([
                'auth_mode' => 'session',
                'user_id' => (int) $user->id,
                'api_token_id' => null,
            ]);

            return $next($request);
        }

        abort(401, 'Unauthenticated.');
    }
}
