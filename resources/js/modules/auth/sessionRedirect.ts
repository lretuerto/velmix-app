export function safeRedirectPath(value: string | null): string {
    if (value === null || value.trim() === '' || !value.startsWith('/') || value.startsWith('//') || value.startsWith('/login')) {
        return '/';
    }

    return value;
}

export function buildPostLoginUrl(path: string, tenant: string | null | undefined): string {
    const query = new URLSearchParams();
    const cleanTenant = (tenant ?? '').trim();

    if (cleanTenant !== '') {
        query.set('tenant', cleanTenant);
    }

    const search = query.toString();

    return `/app${safeRedirectPath(path)}${search !== '' ? `?${search}` : ''}`;
}
