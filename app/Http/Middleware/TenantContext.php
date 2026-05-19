<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

class TenantContext
{
    public function handle(Request $request, Closure $next): Response
    {
        $tenantId = (int) $request->header('X-Tenant-Id', 0);

        if ($tenantId <= 0) {
            return response()->json([
                'message' => 'Tenant context is required',
            ], 400);
        }

        // CLAVE: esto lo necesita RequirePermission
        $request->attributes->set('tenant_id', $tenantId);

        // Opcional, pero útil para otras capas
        app()->instance('currentTenantId', $tenantId);
        Log::withContext([
            'tenant_id' => $tenantId,
        ]);

        return $next($request);
    }
}
