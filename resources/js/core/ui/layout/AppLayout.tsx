import { useState } from 'react';
import { Outlet, NavLink, useLocation, Link } from 'react-router-dom';
import { useAppShell } from '@/core/app/hooks';
import { logoutSession } from '@/core/auth/api/session';
import { hasPermission } from '@/core/auth/permissions';
import { TenantSwitcher } from '@/core/ui/layout/TenantSwitcher';
import { cn } from '@/shared/utils/cn';

interface NavItem {
    label: string;
    to: string;
    caption: string;
    permission?: string;
}

const navigation: NavItem[] = [
    {
        label: 'Workspace',
        to: '/',
        caption: 'Estado del shell, sesion y tenant activo.',
    },
    {
        label: 'Platform',
        to: '/platform',
        caption: 'Observabilidad, control tower y estado operacional.',
        permission: 'reports.platform-observability.read',
    },
    {
        label: 'Productos',
        to: '/inventory/products',
        caption: 'Maestro base de inventario para Sprint 1.',
        permission: 'inventory.product.read',
    },
    {
        label: 'Clientes',
        to: '/sales/customers',
        caption: 'Maestro comercial y cuentas por cobrar.',
        permission: 'sales.customer.read',
    },
    {
        label: 'Cobranzas',
        to: '/sales/receivables',
        caption: 'Receivables, aging, pagos y follow-ups.',
        permission: 'sales.receivable.read',
    },
    {
        label: 'POS',
        to: '/pos/sales',
        caption: 'Checkout comercial, ventas FIFO y detalle POS.',
        permission: 'pos.sale.read',
    },
    {
        label: 'Caja',
        to: '/cash/sessions',
        caption: 'Apertura, cierre y movimientos manuales.',
        permission: 'cash.session.read',
    },
];

export function AppLayout() {
    const boot = useAppShell();
    const location = useLocation();

    return (
        <div className="min-h-screen bg-transparent text-[var(--velmix-ink)]">
            <div className="mx-auto grid min-h-screen max-w-[1760px] grid-cols-1 gap-5 px-3 py-3 lg:grid-cols-[310px_minmax(0,1fr)] lg:px-5">
                <aside className="overflow-hidden rounded-[1.35rem] border border-white/10 bg-[var(--velmix-sidebar)] text-white shadow-[var(--velmix-shadow)] lg:sticky lg:top-5 lg:max-h-[calc(100vh-2.5rem)]">
                    <div className="relative border-b border-white/10 p-5">
                        <div className="absolute inset-x-0 top-0 h-28 bg-[radial-gradient(circle_at_top_left,rgb(180_91_42_/_0.36),transparent_62%)]" />
                        <div className="relative">
                            <p className="text-[11px] font-black uppercase tracking-[0.28em] text-[#f0b27f]">
                                VELMIX OPS
                            </p>
                            <h1 className="mt-3 text-2xl font-black leading-tight tracking-[-0.04em]">
                                Frontend Command Center
                            </h1>
                            <p className="mt-3 text-sm leading-6 text-white/66">
                                Operacion comercial quote-first para POS, caja, cartera, catalogo y clientes.
                            </p>
                        </div>
                    </div>

                    <div className="space-y-4 p-4">
                        <div className="rounded-[var(--velmix-radius-lg)] border border-white/10 bg-white/[0.06] p-4">
                            <p className="text-[10px] font-black uppercase tracking-[0.22em] text-white/45">
                                Sesion activa
                            </p>
                            <p className="mt-2 text-base font-black">
                                {boot.auth.authenticated ? boot.auth.user?.name : 'Guest shell'}
                            </p>
                            <p className="mt-1 text-xs leading-5 text-white/60">
                                {boot.auth.authenticated
                                    ? boot.auth.user?.email
                                    : 'Inicia sesion para activar RBAC, tenant y datos reales.'}
                            </p>
                            <div className="mt-4">
                                {boot.auth.authenticated ? (
                                    <LogoutButton />
                                ) : (
                                    <Link
                                        to={{ pathname: '/login', search: location.search }}
                                        className="inline-flex rounded-[var(--velmix-radius-md)] bg-white px-3 py-2 text-xs font-black text-[var(--velmix-sidebar)] transition hover:opacity-90"
                                    >
                                        Iniciar sesion
                                    </Link>
                                )}
                            </div>
                        </div>

                        {boot.tenant.memberships.length > 0 && (
                            <div className="rounded-[var(--velmix-radius-lg)] border border-white/10 bg-white/[0.04] p-4">
                                <TenantSwitcher />
                            </div>
                        )}
                    </div>

                    <nav className="space-y-1 overflow-y-auto px-4 pb-5">
                        {navigation.map((item) => {
                            const allowed = item.permission === undefined || hasPermission(boot.rbac.permissions, item.permission);

                            return (
                                <NavLink
                                    key={item.to}
                                    to={{ pathname: item.to, search: location.search }}
                                    className={({ isActive }) =>
                                        cn(
                                            'group block rounded-[var(--velmix-radius-lg)] border px-4 py-3 transition',
                                            isActive
                                                ? 'border-[#f0b27f]/60 bg-white text-[var(--velmix-sidebar)] shadow-[0_18px_42px_rgb(0_0_0_/_0.18)]'
                                                : 'border-transparent text-white/72 hover:border-white/10 hover:bg-white/[0.07] hover:text-white',
                                        )
                                    }
                                >
                                    <div className="flex items-center justify-between gap-3">
                                        <span className="text-sm font-black">{item.label}</span>
                                        {!allowed && (
                                            <span className="rounded-full bg-[#f6d58d] px-2 py-1 text-[9px] font-black uppercase tracking-[0.16em] text-[#4b3110]">
                                                locked
                                            </span>
                                        )}
                                    </div>
                                    <p className="mt-1 text-xs leading-5 opacity-70">{item.caption}</p>
                                </NavLink>
                            );
                        })}
                    </nav>
                </aside>

                <div className="flex min-h-screen min-w-0 flex-col gap-5">
                    <header className="velmix-card overflow-hidden">
                        <div className="flex flex-col gap-4 border-b border-[var(--velmix-border)] bg-white/60 px-5 py-4 lg:flex-row lg:items-center lg:justify-between">
                            <div>
                                <p className="text-[11px] font-black uppercase tracking-[0.24em] text-[var(--velmix-brand)]">
                                    {boot.app.frontend_stage}
                                </p>
                                <h2 className="mt-1 text-3xl font-black tracking-[-0.05em]">
                                    {boot.tenant.selected?.name ?? 'Workspace sin tenant seleccionado'}
                                </h2>
                                <p className="mt-2 max-w-4xl text-sm leading-6 text-[var(--velmix-muted)]">
                                    {boot.tenant.selected !== null
                                        ? `Tenant code: ${boot.tenant.selected.code}. Request correlation: ${boot.app.request_id}.`
                                        : 'El shell ya entiende membresias, roles y permisos. El siguiente paso es codificar vistas funcionales sobre un tenant activo.'}
                                </p>
                            </div>
                            <dl className="grid grid-cols-3 gap-2 text-sm">
                                <MetricPill label="Permisos" value={String(boot.rbac.permissions.length)} />
                                <MetricPill label="Roles" value={String(boot.rbac.roles.length)} />
                                <MetricPill label="Membresias" value={String(boot.tenant.memberships.length)} />
                            </dl>
                        </div>
                        <div className="grid gap-3 px-5 py-3 text-xs text-[var(--velmix-muted)] md:grid-cols-3">
                            <span className="font-semibold text-[var(--velmix-success)]">Session-first activo</span>
                            <span>RBAC aplicado por modulo</span>
                            <span>Quote-first POS en UAT</span>
                        </div>
                    </header>

                    <main className="flex-1">
                        <Outlet />
                    </main>
                </div>
            </div>
        </div>
    );
}

interface MetricPillProps {
    label: string;
    value: string;
}

function MetricPill({ label, value }: MetricPillProps) {
    return (
        <div className="rounded-[var(--velmix-radius-md)] border border-[var(--velmix-border)] bg-white px-4 py-3 text-right shadow-[0_8px_22px_rgb(16_35_30_/_0.04)]">
            <dt className="text-[9px] font-black uppercase tracking-[0.18em] text-[var(--velmix-muted)]">{label}</dt>
            <dd className="mt-1 text-xl font-black tracking-[-0.04em]">{value}</dd>
        </div>
    );
}

function LogoutButton() {
    const [isPending, setIsPending] = useState(false);
    const [error, setError] = useState<string | null>(null);

    const logout = () => {
        setIsPending(true);
        setError(null);

        void logoutSession()
            .then(() => {
                window.location.assign('/app/login');
            })
            .catch(() => {
                setError('No se pudo cerrar la sesion. Intenta recargar y volver a intentar.');
                setIsPending(false);
            });
    };

    return (
        <div>
            <button
                type="button"
                onClick={logout}
                disabled={isPending}
                className="inline-flex rounded-[var(--velmix-radius-md)] border border-white/20 px-3 py-2 text-xs font-black text-white/88 transition hover:bg-white/10 disabled:cursor-not-allowed disabled:opacity-60"
            >
                {isPending ? 'Cerrando...' : 'Cerrar sesion'}
            </button>
            {error !== null && <p className="mt-2 text-xs text-red-200">{error}</p>}
        </div>
    );
}
