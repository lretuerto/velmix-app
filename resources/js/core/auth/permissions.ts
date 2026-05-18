export function hasPermission(permissions: string[], requiredPermission: string): boolean {
    return permissions.includes(requiredPermission);
}

export function hasAnyPermission(permissions: string[], requiredPermissions: string[]): boolean {
    return requiredPermissions.some((permission) => permissions.includes(permission));
}
