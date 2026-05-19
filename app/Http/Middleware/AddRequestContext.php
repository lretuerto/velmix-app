<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

class AddRequestContext
{
    public function handle(Request $request, Closure $next): Response
    {
        $requestId = $this->resolveRequestId($request);

        $request->attributes->set('request_id', $requestId);
        app()->instance('request_id', $requestId);
        Log::withContext([
            'request_id' => $requestId,
            'request_method' => $request->method(),
            'request_path' => '/'.$request->path(),
            'request_ip' => $request->ip(),
        ]);

        $response = $next($request);
        $response->headers->set('X-Request-Id', $requestId);

        return $response;
    }

    private function resolveRequestId(Request $request): string
    {
        $header = trim((string) $request->headers->get('X-Request-Id', ''));

        if ($header !== '' && strlen($header) <= 120 && preg_match('/^[A-Za-z0-9._:-]+$/', $header) === 1) {
            return $header;
        }

        return (string) Str::uuid();
    }
}
