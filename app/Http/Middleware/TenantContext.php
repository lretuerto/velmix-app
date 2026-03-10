<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class TenantContext
{
    public function handle(Request $request, Closure $next): Response
    {
        $tenantId = $request->header('X-Tenant-Id');

        if (empty($tenantId)) {
            return response()->json([
                'message' => 'Tenant context is required'
            ], 400);
        }

        // Guardar contexto para uso posterior
        app()->instance('currentTenantId', $tenantId);

        return $next($request);
    }
}
