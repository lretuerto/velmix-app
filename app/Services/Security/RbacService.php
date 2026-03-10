<?php

namespace App\Services\Security;

class RbacService
{
    public function can(array $permissions, string $requiredPermission): bool
    {
        return in_array($requiredPermission, $permissions, true);
    }
}
