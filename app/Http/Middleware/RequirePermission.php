<?php

namespace App\Http\Middleware;

use App\Models\ApiToken;
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

        if (! $this->tokenAllowsPermission(app()->bound('currentApiToken') ? app('currentApiToken') : null, $permission)) {
            abort(403, "API token missing ability: {$permission}");
        }

        if (! $this->rbac->userHasPermission($tenantId, $userId, $permission)) {
            abort(403, "Missing permission: {$permission}");
        }

        return $next($request);
    }

    private function tokenAllowsPermission(mixed $token, string $permission): bool
    {
        if (! $token instanceof ApiToken) {
            return true;
        }

        $abilities = collect($token->abilities ?? [])
            ->filter(fn (mixed $ability) => is_string($ability))
            ->map(fn (string $ability) => trim($ability))
            ->filter()
            ->unique()
            ->values()
            ->all();

        if ($abilities === [] || in_array('*', $abilities, true)) {
            return true;
        }

        foreach ($abilities as $ability) {
            if ($ability === $permission) {
                return true;
            }

            if (str_ends_with($ability, '.*') && str_starts_with($permission, substr($ability, 0, -1))) {
                return true;
            }
        }

        return false;
    }
}
