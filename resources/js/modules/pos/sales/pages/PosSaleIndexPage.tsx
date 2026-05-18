import { startTransition, useDeferredValue, useRef, useState } from 'react';
import { createIdempotencyKey } from '@/core/api/client';
import { describeApiError } from '@/core/api/errors';
import { useAppShell } from '@/core/app/hooks';
import { hasPermission } from '@/core/auth/permissions';
import { StatePanel } from '@/core/ui/feedback/StatePanel';
import { useToast } from '@/core/ui/feedback/toast';
import { useProducts } from '@/modules/inventory/products/hooks/useProducts';
import { PosSaleForm } from '@/modules/pos/sales/components/PosSaleForm';
import { PosQuotePreview } from '@/modules/pos/sales/components/PosQuotePreview';
import { SaleDetailPanel } from '@/modules/pos/sales/components/SaleDetailPanel';
import { SaleTable } from '@/modules/pos/sales/components/SaleTable';
import { usePosSaleDetail } from '@/modules/pos/sales/hooks/usePosSaleDetail';
import { usePosSales } from '@/modules/pos/sales/hooks/usePosSales';
import { toPricingQuoteCheckoutPayload, toPricingQuoteCreatePayload, type PosSaleCreateFormData } from '@/modules/pos/sales/schema';
import { useCheckoutPricingQuote } from '@/modules/pricing/quotes/hooks/useCheckoutPricingQuote';
import { useCreatePricingQuote } from '@/modules/pricing/quotes/hooks/useCreatePricingQuote';
import type { PricingQuote } from '@/modules/pricing/quotes/types';
import { useCustomers } from '@/modules/sales/customers/hooks/useCustomers';
import { PageHeader } from '@/shared/components/PageHeader';
import { formatCurrency, formatNumber } from '@/shared/utils/formatters';

export function PosSaleIndexPage() {
    const boot = useAppShell();
    const toast = useToast();
    const productsQuery = useProducts();
    const customersQuery = useCustomers();
    const salesQuery = usePosSales();
    const createQuoteMutation = useCreatePricingQuote();
    const checkoutQuoteMutation = useCheckoutPricingQuote();
    const [search, setSearch] = useState('');
    const [selectedSaleId, setSelectedSaleId] = useState<number | null>(null);
    const [quotedDraft, setQuotedDraft] = useState<{
        formValues: PosSaleCreateFormData;
        quote: PricingQuote;
        checkoutIdempotencyKey: string;
    } | null>(null);
    const [formVersion, setFormVersion] = useState(0);
    const quoteCreateIdempotencyKeyRef = useRef<string | null>(null);
    const quoteCreateFingerprintRef = useRef<string | null>(null);
    const deferredSearch = useDeferredValue(search);
    const detailQuery = usePosSaleDetail({ saleId: selectedSaleId });
    const canExecuteSale = hasPermission(boot.rbac.permissions, 'pos.sale.execute');
    const canCreateQuote = hasPermission(boot.rbac.permissions, 'pricing.quote.create');
    const sales = salesQuery.data ?? [];
    const selectedSale = sales.find((sale) => sale.id === selectedSaleId) ?? null;
    const activeProducts = (productsQuery.data ?? []).filter((product) => product.status === 'active');
    const activeCustomers = (customersQuery.data ?? []).filter((customer) => customer.status === 'active');
    const productsById = new Map(activeProducts.map((product) => [product.id, product]));
    const normalizedSearch = deferredSearch.trim().toLowerCase();
    const filteredSales = sales.filter((sale) => {
        if (normalizedSearch === '') {
            return true;
        }

        return (
            sale.reference.toLowerCase().includes(normalizedSearch)
            || sale.payment_method.toLowerCase().includes(normalizedSearch)
            || sale.status.toLowerCase().includes(normalizedSearch)
            || (sale.customer?.name ?? '').toLowerCase().includes(normalizedSearch)
        );
    });

    const completedCount = sales.filter((sale) => sale.status === 'completed').length;
    const creditedCount = sales.filter((sale) => sale.status === 'credited').length;
    const totalSalesAmount = sales.reduce((carry, sale) => carry + sale.total_amount, 0);
    const grossMarginTotal = sales.reduce((carry, sale) => carry + sale.gross_margin, 0);

    const handleRefresh = async () => {
        await Promise.all([
            productsQuery.refetch(),
            customersQuery.refetch(),
            salesQuery.refetch(),
            selectedSaleId !== null ? detailQuery.refetch() : Promise.resolve(),
        ]);
    };

    const handleSelectSale = (saleId: number) => {
        startTransition(() => {
            setSelectedSaleId(saleId);
        });
    };

    const requestQuote = async (values: PosSaleCreateFormData) => {
        const payload = toPricingQuoteCreatePayload(values);
        const fingerprint = JSON.stringify(payload);

        if (quoteCreateIdempotencyKeyRef.current === null || quoteCreateFingerprintRef.current !== fingerprint) {
            quoteCreateIdempotencyKeyRef.current = createIdempotencyKey('pos-pricing-quote-create');
            quoteCreateFingerprintRef.current = fingerprint;
        }

        const quote = await createQuoteMutation.mutateAsync({
            payload,
            idempotencyKey: quoteCreateIdempotencyKeyRef.current,
        });

        quoteCreateIdempotencyKeyRef.current = null;
        quoteCreateFingerprintRef.current = null;

        return quote;
    };

    const handleSubmit = async (values: PosSaleCreateFormData) => {
        createQuoteMutation.reset();
        checkoutQuoteMutation.reset();
        setQuotedDraft(null);

        try {
            const quote = await requestQuote(values);

            setQuotedDraft({
                formValues: JSON.parse(JSON.stringify(values)) as PosSaleCreateFormData,
                quote,
                checkoutIdempotencyKey: createIdempotencyKey(`pos-pricing-quote-${quote.id}-checkout`),
            });
            toast.success({
                title: 'Quote generado',
                description: `La cotizacion #${quote.id} quedo lista por ${formatCurrency(quote.summary.total_amount, quote.currency)}.`,
            });

            return false;
        } catch (error) {
            toast.danger({
                title: 'No pudimos generar la cotizacion',
                description: describeApiError(error),
            });

            return false;
        }
    };

    const handleConfirmQuotedSale = async () => {
        if (quotedDraft === null) {
            return;
        }

        checkoutQuoteMutation.reset();

        try {
            const completed = await checkoutQuoteMutation.mutateAsync({
                quoteId: quotedDraft.quote.id,
                payload: toPricingQuoteCheckoutPayload(quotedDraft.formValues, quotedDraft.quote, productsById),
                idempotencyKey: quotedDraft.checkoutIdempotencyKey,
            });
            const created = completed.sale;

            if (quotedDraft.quote.id !== completed.quote.id) {
                throw new Error('La respuesta del checkout no coincide con la cotizacion confirmada.');
            }

            startTransition(() => {
                setSelectedSaleId(created.sale_id);
            });
            setQuotedDraft(null);
            setFormVersion((value) => value + 1);
            toast.success({
                title: 'Venta registrada',
                description: `La venta ${created.reference} se registro por ${formatCurrency(created.total_amount)}.`,
            });
        } catch (error) {
            toast.danger({
                title: 'No pudimos confirmar la venta',
                description: describeApiError(error),
            });
        }
    };

    const handleRequote = async () => {
        if (quotedDraft === null) {
            return;
        }

        createQuoteMutation.reset();
        checkoutQuoteMutation.reset();

        try {
            const quote = await requestQuote(quotedDraft.formValues);

            setQuotedDraft({
                formValues: JSON.parse(JSON.stringify(quotedDraft.formValues)) as PosSaleCreateFormData,
                quote,
                checkoutIdempotencyKey: createIdempotencyKey(`pos-pricing-quote-${quote.id}-checkout`),
            });
            toast.success({
                title: 'Quote actualizado',
                description: `La nueva cotizacion #${quote.id} quedo lista por ${formatCurrency(quote.summary.total_amount, quote.currency)}.`,
            });
        } catch (error) {
            toast.danger({
                title: 'No pudimos recotizar',
                description: describeApiError(error),
            });
        }
    };

    return (
        <div className="space-y-5">
            <PageHeader
                eyebrow="POS"
                title="Ventas POS"
                description="Cockpit de ventas con quote server-side, pricing/promotions, checkout idempotente, stock FIFO y trazabilidad de caja/cartera."
                actions={
                    <button
                        type="button"
                        className="velmix-button-secondary px-4 py-2 text-sm disabled:cursor-not-allowed disabled:opacity-60"
                        onClick={() => {
                            void handleRefresh();
                        }}
                        disabled={
                            productsQuery.isFetching
                            || customersQuery.isFetching
                            || salesQuery.isFetching
                            || detailQuery.isFetching
                        }
                    >
                        {productsQuery.isFetching || customersQuery.isFetching || salesQuery.isFetching || detailQuery.isFetching
                            ? 'Refrescando...'
                            : 'Refrescar POS'}
                    </button>
                }
            />

            <section className="grid gap-3 xl:grid-cols-4 md:grid-cols-2">
                <MetricCard label="Ventas" value={formatNumber(sales.length)} help="Ventas visibles para el tenant activo." />
                <MetricCard label="Completadas" value={formatNumber(completedCount)} help="Ventas activas sin anulacion ni credito total." />
                <MetricCard label="Creditadas" value={formatNumber(creditedCount)} help="Ventas con credit note registrada." />
                <MetricCard label="Importe total" value={formatCurrency(totalSalesAmount)} help={`Margen agregado ${formatCurrency(grossMarginTotal)}.`} />
            </section>

            {(salesQuery.isError || productsQuery.isError || customersQuery.isError) && (
                <StatePanel
                    tone="danger"
                    title="No pudimos cargar el cockpit POS"
                    description={salesQuery.isError
                        ? describeApiError(salesQuery.error)
                        : productsQuery.isError
                            ? describeApiError(productsQuery.error)
                            : describeApiError(customersQuery.error)}
                />
            )}

            <section className="grid gap-5 2xl:grid-cols-[minmax(0,1.15fr)_minmax(520px,0.85fr)]">
                <article className="velmix-card overflow-hidden">
                    <div className="flex flex-col gap-4 md:flex-row md:items-end md:justify-between">
                        <div className="px-5 pt-5">
                            <p className="text-[11px] font-black uppercase tracking-[0.22em] text-[var(--velmix-brand)]">
                                Libro de ventas
                            </p>
                            <h2 className="mt-2 text-2xl font-black tracking-[-0.04em]">Ventas recientes</h2>
                            <p className="mt-1 max-w-2xl text-sm leading-6 text-[var(--velmix-muted)]">
                                Monitorea venta, pago, margen y trazabilidad comercial desde una vista de operacion.
                            </p>
                        </div>
                        <div className="grid gap-2 px-5 pt-5">
                            <label className="text-[10px] font-black uppercase tracking-[0.18em] text-[var(--velmix-muted)]" htmlFor="pos-sale-search">
                                Buscar
                            </label>
                            <input
                                id="pos-sale-search"
                                type="text"
                                value={search}
                                onChange={(event) => setSearch(event.target.value)}
                                className="velmix-input px-3 py-2 text-sm"
                                placeholder="Referencia, cliente, metodo o estado"
                            />
                        </div>
                    </div>

                    <div className="mt-5 border-t border-[var(--velmix-border)] bg-white/70 p-4">
                        <SaleTable
                            sales={filteredSales}
                            isFetching={salesQuery.isFetching}
                            selectedSaleId={selectedSaleId}
                            onSelectSale={handleSelectSale}
                        />
                    </div>
                </article>

                <article className="velmix-card overflow-hidden">
                    <div className="border-b border-[var(--velmix-border)] bg-[linear-gradient(135deg,#ffffff_0%,#f6efe7_100%)] px-5 py-5">
                        <p className="text-[11px] font-black uppercase tracking-[0.22em] text-[var(--velmix-brand)]">
                            Checkout operativo
                        </p>
                        <h2 className="mt-2 text-2xl font-black tracking-[-0.04em]">Cotizar y vender</h2>
                        <p className="mt-2 text-sm leading-6 text-[var(--velmix-muted)]">
                            Define carrito, cliente, controlados y metodo de pago. El backend resuelve precio y promociones antes de confirmar.
                        </p>
                    </div>

                    <div className="bg-white/80 p-5">
                        {canExecuteSale && canCreateQuote ? (
                            <PosSaleForm
                                key={formVersion}
                                products={activeProducts}
                                customers={activeCustomers}
                                isPending={createQuoteMutation.isPending}
                                errorMessage={createQuoteMutation.isError ? describeApiError(createQuoteMutation.error) : null}
                                submitLabel="Cotizar venta POS"
                                onSubmit={handleSubmit}
                            />
                        ) : canExecuteSale ? (
                            <StatePanel
                                tone="warning"
                                title="Falta permiso de pricing"
                                description="El usuario actual puede ejecutar ventas, pero ahora el flujo POS requiere tambien `pricing.quote.create` para cotizar antes de confirmar."
                            />
                        ) : (
                            <StatePanel
                                tone="warning"
                                title="Sin permiso de ejecucion"
                                description="El usuario actual puede revisar ventas, pero no tiene el permiso `pos.sale.execute` para confirmar una nueva venta POS."
                            />
                        )}
                    </div>

                    <div className="border-t border-[var(--velmix-border)] bg-[var(--velmix-panel-muted)] p-5">
                        <PosQuotePreview
                            quote={quotedDraft?.quote ?? null}
                            isConfirming={checkoutQuoteMutation.isPending}
                            isRequoting={createQuoteMutation.isPending && quotedDraft !== null}
                            onConfirm={() => {
                                void handleConfirmQuotedSale();
                            }}
                            onDiscard={() => setQuotedDraft(null)}
                            onRequote={() => {
                                void handleRequote();
                            }}
                        />
                    </div>
                </article>
            </section>

            <SaleDetailPanel
                sale={selectedSale}
                detail={detailQuery.data}
                isLoading={detailQuery.isLoading || detailQuery.isFetching}
                isError={detailQuery.isError}
                errorMessage={detailQuery.isError ? describeApiError(detailQuery.error) : null}
                onRefresh={() => {
                    void detailQuery.refetch();
                }}
            />
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
