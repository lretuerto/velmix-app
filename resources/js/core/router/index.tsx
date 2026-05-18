import { lazy, Suspense, type ReactNode } from 'react';
import { Link, useRoutes } from 'react-router-dom';
import { PermissionBoundary } from '@/core/auth/PermissionBoundary';
import { AppLayout } from '@/core/ui/layout/AppLayout';
import { StatePanel } from '@/core/ui/feedback/StatePanel';

const WorkspaceHomePage = lazy(() => import('@/modules/home/pages/WorkspaceHomePage').then((module) => ({ default: module.WorkspaceHomePage })));
const LoginPage = lazy(() => import('@/modules/auth/pages/LoginPage').then((module) => ({ default: module.LoginPage })));
const PlatformOverviewPage = lazy(() => import('@/modules/platform/pages/PlatformOverviewPage').then((module) => ({ default: module.PlatformOverviewPage })));
const ProductIndexPage = lazy(() => import('@/modules/inventory/products/pages/ProductIndexPage').then((module) => ({ default: module.ProductIndexPage })));
const CustomerIndexPage = lazy(() => import('@/modules/sales/customers/pages/CustomerIndexPage').then((module) => ({ default: module.CustomerIndexPage })));
const SaleReceivableIndexPage = lazy(() => import('@/modules/sales/receivables/pages/SaleReceivableIndexPage').then((module) => ({ default: module.SaleReceivableIndexPage })));
const PosSaleIndexPage = lazy(() => import('@/modules/pos/sales/pages/PosSaleIndexPage').then((module) => ({ default: module.PosSaleIndexPage })));
const CashSessionIndexPage = lazy(() => import('@/modules/cash/sessions/pages/CashSessionIndexPage').then((module) => ({ default: module.CashSessionIndexPage })));

function LazyRoute({ children }: { children: ReactNode }) {
    return (
        <Suspense
            fallback={(
                <StatePanel
                    tone="neutral"
                    title="Cargando modulo"
                    description="Estamos cargando solo el modulo solicitado para mantener rapido el inicio del frontend."
                />
            )}
        >
            {children}
        </Suspense>
    );
}

export function AppRouter() {
    return useRoutes([
        {
            path: '/login',
            element: (
                <LazyRoute>
                    <LoginPage />
                </LazyRoute>
            ),
        },
        {
            path: '/',
            element: <AppLayout />,
            children: [
                {
                    index: true,
                    element: (
                        <LazyRoute>
                            <WorkspaceHomePage />
                        </LazyRoute>
                    ),
                },
                {
                    path: 'platform',
                    element: (
                        <PermissionBoundary
                            permission="reports.platform-observability.read"
                            title="Acceso restringido"
                            description="El usuario actual no tiene el permiso `reports.platform-observability.read` para abrir la vista operativa de plataforma."
                        >
                            <LazyRoute>
                                <PlatformOverviewPage />
                            </LazyRoute>
                        </PermissionBoundary>
                    ),
                },
                {
                    path: 'inventory/products',
                    element: (
                        <PermissionBoundary
                            permission="inventory.product.read"
                            title="Acceso restringido"
                            description="El usuario actual no tiene el permiso `inventory.product.read` para abrir el modulo de productos."
                        >
                            <LazyRoute>
                                <ProductIndexPage />
                            </LazyRoute>
                        </PermissionBoundary>
                    ),
                },
                {
                    path: 'sales/customers',
                    element: (
                        <PermissionBoundary
                            permission="sales.customer.read"
                            title="Acceso restringido"
                            description="El usuario actual no tiene el permiso `sales.customer.read` para abrir el modulo de clientes."
                        >
                            <LazyRoute>
                                <CustomerIndexPage />
                            </LazyRoute>
                        </PermissionBoundary>
                    ),
                },
                {
                    path: 'sales/receivables',
                    element: (
                        <PermissionBoundary
                            permission="sales.receivable.read"
                            title="Acceso restringido"
                            description="El usuario actual no tiene el permiso `sales.receivable.read` para abrir el modulo de cuentas por cobrar."
                        >
                            <LazyRoute>
                                <SaleReceivableIndexPage />
                            </LazyRoute>
                        </PermissionBoundary>
                    ),
                },
                {
                    path: 'pos/sales',
                    element: (
                        <PermissionBoundary
                            permission="pos.sale.read"
                            title="Acceso restringido"
                            description="El usuario actual no tiene el permiso `pos.sale.read` para abrir el modulo de ventas POS."
                        >
                            <LazyRoute>
                                <PosSaleIndexPage />
                            </LazyRoute>
                        </PermissionBoundary>
                    ),
                },
                {
                    path: 'cash/sessions',
                    element: (
                        <PermissionBoundary
                            permission="cash.session.read"
                            title="Acceso restringido"
                            description="El usuario actual no tiene el permiso `cash.session.read` para abrir el modulo de caja."
                        >
                            <LazyRoute>
                                <CashSessionIndexPage />
                            </LazyRoute>
                        </PermissionBoundary>
                    ),
                },
            ],
        },
        {
            path: '*',
            element: (
                <div className="mx-auto flex min-h-screen max-w-3xl items-center justify-center px-6">
                    <StatePanel
                        tone="danger"
                        title="Ruta no encontrada"
                        description="La ruta del frontend no existe todavia. El shell de Sprint 0 queda listo para agregar modulos sin rehacer la base."
                        actions={
                            <Link
                                to="/"
                                className="inline-flex rounded-[var(--velmix-radius-md)] bg-[var(--velmix-brand)] px-4 py-2 text-sm font-semibold text-white transition hover:opacity-90"
                            >
                                Volver al workspace
                            </Link>
                        }
                    />
                </div>
            ),
        },
    ]);
}
