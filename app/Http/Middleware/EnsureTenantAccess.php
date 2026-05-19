<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

class EnsureTenantAccess
{
    public function handle(Request $request, Closure $next): Response
    {
        $tenantId = (int) $request->attributes->get('tenant_id', 0);
        $userId = (int) optional($request->user())->id;

        if ($tenantId <= 0 || $userId <= 0) {
            abort(403, 'Tenant context or authenticated user missing.');
        }

        $tenant = DB::table('tenants')->where('id', $tenantId)->first();

        if ($tenant === null) {
            abort(404, 'Tenant not found.');
        }

        if (app()->bound('currentApiToken')) {
            $apiToken = app('currentApiToken');

            if ((int) $apiToken->tenant_id !== $tenantId) {
                abort(403, 'API token does not belong to tenant.');
            }
        }

        $hasAccess = DB::table('tenant_user')
            ->where('tenant_id', $tenantId)
            ->where('user_id', $userId)
            ->exists();

        if (! $hasAccess) {
            abort(403, 'User is not assigned to tenant.');
        }

        app()->instance('currentTenant', $tenant);
        Log::withContext([
            'tenant_code' => (string) $tenant->code,
            'route_uri' => $request->route()?->uri(),
        ]);

        return $next($request);
    }
}
