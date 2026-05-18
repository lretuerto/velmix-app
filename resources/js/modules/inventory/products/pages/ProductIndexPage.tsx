import { useDeferredValue, useState } from 'react';
import { describeApiError, toApiError } from '@/core/api/errors';
import { useAppShell } from '@/core/app/hooks';
import { hasPermission } from '@/core/auth/permissions';
import { ApiErrorPanel } from '@/core/ui/feedback/ApiErrorPanel';
import { StatePanel } from '@/core/ui/feedback/StatePanel';
import { useToast } from '@/core/ui/feedback/toast';
import { ProductCreateForm } from '@/modules/inventory/products/components/ProductCreateForm';
import { ProductTable } from '@/modules/inventory/products/components/ProductTable';
import { useCreateProduct } from '@/modules/inventory/products/hooks/useCreateProduct';
import { useProducts } from '@/modules/inventory/products/hooks/useProducts';
import type { ProductCreateFormData } from '@/modules/inventory/products/schema';
import type { InventoryProduct } from '@/modules/inventory/products/types';
import { PageHeader } from '@/shared/components/PageHeader';
import { formatCurrency, formatNumber } from '@/shared/utils/formatters';

export function ProductIndexPage() {
    const boot = useAppShell();
    const toast = useToast();
    const productsQuery = useProducts();
    const createProductMutation = useCreateProduct();
    const [search, setSearch] = useState('');
    const [lastCreatedProduct, setLastCreatedProduct] = useState<InventoryProduct | null>(null);
    const deferredSearch = useDeferredValue(search);
    const canCreateProduct = hasPermission(boot.rbac.permissions, 'inventory.product.create');
    const products = productsQuery.data ?? [];
    const normalizedSearch = deferredSearch.trim().toLowerCase();
    const filteredProducts = products.filter((product) => {
        if (normalizedSearch === '') {
            return true;
        }

        return (
            product.sku.toLowerCase().includes(normalizedSearch)
            || product.name.toLowerCase().includes(normalizedSearch)
            || product.status.toLowerCase().includes(normalizedSearch)
        );
    });
    const controlledCount = products.filter((product) => product.is_controlled).length;
    const activeCount = products.filter((product) => product.status === 'active').length;
    const isInitialLoading = productsQuery.isLoading && products.length === 0;
    const catalogAverageCost =
        products.length === 0
            ? 0
            : products.reduce((carry, product) => carry + product.average_cost, 0) / products.length;

    const handleSubmit = async (values: ProductCreateFormData) => {
        setLastCreatedProduct(null);

        try {
            const created = await createProductMutation.mutateAsync(values);

            setLastCreatedProduct(created);
            toast.success({
                title: 'Producto creado',
                description: `El producto ${created.sku} ya esta disponible en el catalogo del tenant activo.`,
            });

            return true;
        } catch (error) {
            toast.danger({
                title: 'No pudimos crear el producto',
                description: describeApiError(error),
            });

            // The mutation state already carries the actionable error for the form.
            return false;
        }
    };

    return (
        <div className="space-y-6">
            <PageHeader
                eyebrow="Inventory"
                title="Productos"
                description="El modulo ya consulta el catalogo real del tenant y permite alta administrativa con idempotencia sobre el endpoint existente."
                actions={
                    <button
                        type="button"
                        className="rounded-[var(--velmix-radius-md)] border border-[var(--velmix-border-strong)] bg-[var(--velmix-panel-strong)] px-4 py-2 text-sm font-semibold text-[var(--velmix-ink)] transition hover:border-[var(--velmix-brand)] disabled:cursor-not-allowed disabled:opacity-60"
                        onClick={() => {
                            void productsQuery.refetch();
                        }}
                        disabled={productsQuery.isFetching}
                    >
                        {productsQuery.isFetching ? 'Refrescando...' : 'Refrescar catalogo'}
                    </button>
                }
            />

            <section className="grid gap-4 xl:grid-cols-4 md:grid-cols-2">
                <MetricCard label="Productos" value={formatNumber(products.length)} help="Total de registros visibles para el tenant activo." />
                <MetricCard label="Controlados" value={formatNumber(controlledCount)} help="Productos marcados para seguimiento especial." />
                <MetricCard label="Activos" value={formatNumber(activeCount)} help="Productos con estado activo en el catalogo." />
                <MetricCard
                    label="Costo promedio"
                    value={formatCurrency(catalogAverageCost)}
                    help="Promedio simple del average cost del catalogo actual."
                />
            </section>

            {isInitialLoading && (
                <StatePanel
                    tone="neutral"
                    title="Cargando catalogo"
                    description="Estamos sincronizando productos, costos y estado comercial del tenant antes de habilitar busqueda o alta administrativa."
                />
            )}

            {productsQuery.isError && (
                <ApiErrorPanel
                    title="No pudimos cargar el catalogo"
                    error={productsQuery.error}
                    retryLabel="Refrescar catalogo"
                    onRetry={() => {
                        void productsQuery.refetch();
                    }}
                />
            )}

            <section className="grid gap-6 xl:grid-cols-[minmax(0,1fr)_340px]">
                <article className="rounded-[var(--velmix-radius-xl)] border border-[var(--velmix-border)] bg-[var(--velmix-panel)] p-6 shadow-[var(--velmix-shadow)]">
                    <div className="flex flex-col gap-4 md:flex-row md:items-end md:justify-between">
                        <div>
                            <p className="text-xs font-semibold uppercase tracking-[0.18em] text-[var(--velmix-brand)]">
                                Catalogo
                            </p>
                            <h2 className="mt-2 text-2xl font-semibold">Listado de productos</h2>
                            <p className="mt-2 text-sm leading-6 text-[var(--velmix-muted)]">
                                Filtro local sobre SKU, nombre y estado. La alta invalida y rehidrata el cache del tenant actual.
                            </p>
                        </div>
                        <label className="grid gap-2">
                            <span className="text-xs font-semibold uppercase tracking-[0.16em] text-[var(--velmix-muted)]">
                                Buscar
                            </span>
                            <input
                                type="text"
                                value={search}
                                onChange={(event) => setSearch(event.target.value)}
                                className="velmix-input px-3 py-2 text-sm"
                                placeholder="SKU, nombre o estado"
                            />
                        </label>
                    </div>

                    <div className="mt-5 rounded-[var(--velmix-radius-lg)] border border-[var(--velmix-border)] bg-[var(--velmix-panel-strong)] p-4">
                        <ProductTable products={filteredProducts} isFetching={productsQuery.isFetching} />
                    </div>
                </article>

                <article className="rounded-[var(--velmix-radius-xl)] border border-[var(--velmix-border)] bg-[var(--velmix-panel)] p-6 shadow-[var(--velmix-shadow)]">
                    <p className="text-xs font-semibold uppercase tracking-[0.18em] text-[var(--velmix-brand)]">
                        Alta administrativa
                    </p>
                    <h2 className="mt-2 text-2xl font-semibold">Crear producto</h2>
                    <p className="mt-2 text-sm leading-6 text-[var(--velmix-muted)]">
                        La mutacion usa `Idempotency-Key` y respeta las validaciones del backend por tenant.
                    </p>

                    {canCreateProduct ? (
                        <div className="mt-5">
                            <ProductCreateForm
                                isPending={createProductMutation.isPending}
                                errorMessage={
                                    createProductMutation.isError ? describeApiError(createProductMutation.error) : null
                                }
                                fieldErrors={
                                    createProductMutation.isError
                                        ? toApiError(createProductMutation.error).validationErrors
                                        : {}
                                }
                                successMessage={
                                    lastCreatedProduct !== null
                                        ? `Producto ${lastCreatedProduct.sku} creado correctamente.`
                                        : null
                                }
                                onSubmit={handleSubmit}
                            />
                        </div>
                    ) : (
                        <div className="mt-5">
                            <StatePanel
                                tone="warning"
                                title="Acceso de solo lectura"
                                description="El usuario actual puede consultar productos, pero no tiene el permiso `inventory.product.create` para altas administrativas."
                            />
                        </div>
                    )}
                </article>
            </section>
        </div>
    );
}

interface MetricCardProps {
    label: string;
    value: string;
    help: string;
}

function MetricCard({ label, value, help }: MetricCardProps) {
    return (
        <article className="velmix-metric-card p-5">
            <p className="velmix-kicker text-[var(--velmix-muted)]">{label}</p>
            <p className="mt-2 text-3xl font-black tracking-[-0.06em]">{value}</p>
            <p className="mt-3 text-sm leading-6 text-[var(--velmix-muted)]">{help}</p>
        </article>
    );
}
