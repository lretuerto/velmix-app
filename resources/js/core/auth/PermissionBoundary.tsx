import type { PropsWithChildren } from 'react';
import { Link, useLocation } from 'react-router-dom';
import { useAppShell } from '@/core/app/hooks';
import { hasPermission } from '@/core/auth/permissions';
import { StatePanel } from '@/core/ui/feedback/StatePanel';

interface PermissionBoundaryProps extends PropsWithChildren {
    permission: string;
    title: string;
    description: string;
}

export function PermissionBoundary({ children, permission, title, description }: PermissionBoundaryProps) {
    const boot = useAppShell();
    const location = useLocation();

    if (!boot.auth.authenticated) {
        const loginSearch = new URLSearchParams();
        const tenant = new URLSearchParams(location.search).get('tenant');

        loginSearch.set('redirect', location.pathname);

        if (tenant !== null && tenant.trim() !== '') {
            loginSearch.set('tenant', tenant);
        }

        return (
            <StatePanel
                tone="warning"
                title="Sesion requerida"
                description="Esta vista requiere una sesion Laravel activa para cargar datos del tenant con RBAC."
                actions={(
                    <Link
                        to={{ pathname: '/login', search: loginSearch.toString() }}
                        className="inline-flex rounded-[var(--velmix-radius-md)] bg-[var(--velmix-brand)] px-4 py-2 text-sm font-semibold text-white transition hover:opacity-90"
                    >
                        Iniciar sesion
                    </Link>
                )}
            />
        );
    }

    if (boot.tenant.selected === null) {
        return (
            <StatePanel
                tone="neutral"
                title="Selecciona un tenant"
                description="Antes de abrir modulos funcionales necesitamos fijar el tenant activo desde el selector superior."
            />
        );
    }

    if (!hasPermission(boot.rbac.permissions, permission)) {
        return <StatePanel tone="danger" title={title} description={description} />;
    }

    return children;
}
