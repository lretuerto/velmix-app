<?php

namespace App\Services\Frontend;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Facades\DB;

class AppShellBootstrapService
{
    public function build(?Authenticatable $user, ?string $selectedTenantSelector, string $requestId): array
    {
        $userId = $user !== null ? (int) $user->getAuthIdentifier() : 0;
        $memberships = $userId > 0 ? $this->membershipsForUser($userId) : [];

        $selectedTenant = null;
        $selectionError = null;

        if ($memberships !== []) {
            [$selectedTenant, $selectionError] = $this->resolveSelectedTenant($memberships, $selectedTenantSelector);
        } elseif ($selectedTenantSelector !== null && trim($selectedTenantSelector) !== '') {
            $selectionError = 'No hay una sesion autenticada con acceso al tenant solicitado.';
        }

        $roles = [];
        $permissions = [];

        if ($userId > 0 && $selectedTenant !== null) {
            $roles = $this->rolesForMembership($selectedTenant['id'], $userId);
            $permissions = $this->permissionsForMembership($selectedTenant['id'], $userId);
        }

        return [
            'app' => [
                'name' => (string) config('app.name', 'VELMiX ERP'),
                'environment' => (string) app()->environment(),
                'request_id' => $requestId,
                'frontend_stage' => 'sprint-0-base',
            ],
            'auth' => [
                'authenticated' => $user !== null,
                'mode' => $user !== null ? 'session' : 'guest',
                'user' => $user !== null ? [
                    'id' => $userId,
                    'name' => (string) ($user->name ?? ''),
                    'email' => (string) ($user->email ?? ''),
                ] : null,
            ],
            'tenant' => [
                'selected' => $selectedTenant,
                'memberships' => $memberships,
                'selection_error' => $selectionError,
            ],
            'rbac' => [
                'roles' => $roles,
                'permissions' => $permissions,
            ],
            'links' => [
                'health_live' => '/health/live',
                'health_ready' => '/health/ready',
                'auth_me' => '/auth/me',
                'tenant_ping' => '/tenant/ping',
            ],
        ];
    }

    /**
     * @return array<int, array{id:int,code:string,name:string,status:string}>
     */
    private function membershipsForUser(int $userId): array
    {
        return DB::table('tenant_user')
            ->join('tenants', 'tenants.id', '=', 'tenant_user.tenant_id')
            ->where('tenant_user.user_id', $userId)
            ->orderBy('tenants.name')
            ->get([
                'tenants.id',
                'tenants.code',
                'tenants.name',
                'tenants.status',
            ])
            ->map(fn (object $tenant): array => [
                'id' => (int) $tenant->id,
                'code' => (string) $tenant->code,
                'name' => (string) $tenant->name,
                'status' => (string) $tenant->status,
            ])
            ->values()
            ->all();
    }

    /**
     * @param  array<int, array{id:int,code:string,name:string,status:string}>  $memberships
     * @return array{0: array{id:int,code:string,name:string,status:string}|null, 1: string|null}
     */
    private function resolveSelectedTenant(array $memberships, ?string $selectedTenantSelector): array
    {
        $selector = trim((string) $selectedTenantSelector);

        if ($selector === '') {
            return [count($memberships) === 1 ? $memberships[0] : null, null];
        }

        foreach ($memberships as $membership) {
            if ($membership['code'] === $selector || (string) $membership['id'] === $selector) {
                return [$membership, null];
            }
        }

        return [null, 'El tenant solicitado no pertenece a la sesion actual.'];
    }

    /**
     * @return array<int, array{code:string,name:string}>
     */
    private function rolesForMembership(int $tenantId, int $userId): array
    {
        return DB::table('tenant_user_role')
            ->join('roles', 'roles.id', '=', 'tenant_user_role.role_id')
            ->where('tenant_user_role.tenant_id', $tenantId)
            ->where('tenant_user_role.user_id', $userId)
            ->orderBy('roles.code')
            ->get(['roles.code', 'roles.name'])
            ->map(fn (object $role): array => [
                'code' => (string) $role->code,
                'name' => (string) $role->name,
            ])
            ->values()
            ->all();
    }

    /**
     * @return array<int, string>
     */
    private function permissionsForMembership(int $tenantId, int $userId): array
    {
        return DB::table('tenant_user_role')
            ->join('role_permission', 'role_permission.role_id', '=', 'tenant_user_role.role_id')
            ->join('permissions', 'permissions.id', '=', 'role_permission.permission_id')
            ->where('tenant_user_role.tenant_id', $tenantId)
            ->where('tenant_user_role.user_id', $userId)
            ->distinct()
            ->orderBy('permissions.code')
            ->pluck('permissions.code')
            ->map(fn (string $permission): string => (string) $permission)
            ->values()
            ->all();
    }
}
