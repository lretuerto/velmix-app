export interface AppUser {
    id: number;
    name: string;
    email: string;
}

export interface TenantMembership {
    id: number;
    code: string;
    name: string;
    status: string;
}

export interface RoleAssignment {
    code: string;
    name: string;
}

export interface AppBoot {
    app: {
        name: string;
        environment: string;
        request_id: string;
        frontend_stage: string;
    };
    auth: {
        authenticated: boolean;
        mode: 'session' | 'guest';
        user: AppUser | null;
    };
    tenant: {
        selected: TenantMembership | null;
        memberships: TenantMembership[];
        selection_error: string | null;
    };
    rbac: {
        roles: RoleAssignment[];
        permissions: string[];
    };
    links: {
        health_live: string;
        health_ready: string;
        auth_me: string;
        tenant_ping: string;
    };
}

const fallbackBoot: AppBoot = {
    app: {
        name: 'VELMiX ERP',
        environment: 'unknown',
        request_id: 'missing-boot',
        frontend_stage: 'fallback',
    },
    auth: {
        authenticated: false,
        mode: 'guest',
        user: null,
    },
    tenant: {
        selected: null,
        memberships: [],
        selection_error: 'No se encontro el bootstrap inicial del frontend.',
    },
    rbac: {
        roles: [],
        permissions: [],
    },
    links: {
        health_live: '/health/live',
        health_ready: '/health/ready',
        auth_me: '/auth/me',
        tenant_ping: '/tenant/ping',
    },
};

export function readAppBoot(): AppBoot {
    return window.__VELMIX_BOOT__ ?? fallbackBoot;
}
