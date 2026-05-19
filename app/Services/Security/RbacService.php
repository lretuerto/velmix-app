<?php

namespace App\Services\Security;

use Illuminate\Support\Facades\DB;

class RbacService
{
    public function can(array $permissions, string $requiredPermission): bool
    {
        return in_array($requiredPermission, $permissions, true);
    }

    public function userHasPermission(int $tenantId, int $userId, string $requiredPermission): bool
    {
        if ($tenantId <= 0 || $userId <= 0 || $requiredPermission === '') {
            return false;
        }

        $permissions = DB::table('tenant_user_role')
            ->join('role_permission', 'role_permission.role_id', '=', 'tenant_user_role.role_id')
            ->join('permissions', 'permissions.id', '=', 'role_permission.permission_id')
            ->where('tenant_user_role.tenant_id', $tenantId)
            ->where('tenant_user_role.user_id', $userId)
            ->pluck('permissions.code')
            ->all();

        return $this->can($permissions, $requiredPermission);
    }
}
