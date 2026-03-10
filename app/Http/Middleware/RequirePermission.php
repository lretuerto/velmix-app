<?php

namespace App\Http\Middleware;

use App\Services\Security\RbacService;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class RequirePermission
{
    public function __construct(private readonly RbacService $rbac) {}

    public function handle(Request $request, Closure $next, string $permission): Response
    {
        $tenantId = (int) $request->attributes->get('tenant_id', 0);
        $userId = (int) optional($request->user())->id;

        if ($tenantId <= 0 || $userId <= 0) {
            abort(403, 'Tenant context or authenticated user missing.');
        }

        if (! $this->rbac->userHasPermission($tenantId, $userId, $permission)) {
            abort(403, "Missing permission: {$permission}");
        }

        return $next($request);
    }
}
