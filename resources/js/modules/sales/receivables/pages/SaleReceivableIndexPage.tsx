import { startTransition, useDeferredValue, useState } from 'react';
import { describeApiError } from '@/core/api/errors';
import { useAppShell } from '@/core/app/hooks';
import { hasPermission } from '@/core/auth/permissions';
import { ApiErrorPanel } from '@/core/ui/feedback/ApiErrorPanel';
import { StatePanel } from '@/core/ui/feedback/StatePanel';
import { useToast } from '@/core/ui/feedback/toast';
import { ReceivableDetailPanel } from '@/modules/sales/receivables/components/ReceivableDetailPanel';
import { ReceivableTable } from '@/modules/sales/receivables/components/ReceivableTable';
import { useReceivableAging } from '@/modules/sales/receivables/hooks/useReceivableAging';
import {
    useCreateReceivableFollowUp,
    useRegisterReceivablePayment,
} from '@/modules/sales/receivables/hooks/useReceivableMutations';
import { useSaleReceivableDetail } from '@/modules/sales/receivables/hooks/useSaleReceivableDetail';
import { useSaleReceivables } from '@/modules/sales/receivables/hooks/useSaleReceivables';
import {
    toReceivableFollowUpPayload,
    toReceivablePaymentPayload,
    type ReceivableFollowUpFormData,
    type ReceivablePaymentFormData,
} from '@/modules/sales/receivables/schema';
import { PageHeader } from '@/shared/components/PageHeader';
import { formatCurrency, formatNumber } from '@/shared/utils/formatters';

export function SaleReceivableIndexPage() {
    const boot = useAppShell();
    const toast = useToast();
    const receivablesQuery = useSaleReceivables();
    const agingQuery = useReceivableAging();
    const paymentMutation = useRegisterReceivablePayment();
    const followUpMutation = useCreateReceivableFollowUp();
    const [search, setSearch] = useState('');
    const [selectedReceivableId, setSelectedReceivableId] = useState<number | null>(null);
    const deferredSearch = useDeferredValue(search);
    const detailQuery = useSaleReceivableDetail({ receivableId: selectedReceivableId });
    const canPay = hasPermission(boot.rbac.permissions, 'sales.receivable.pay');
    const canCreateFollowUp = hasPermission(boot.rbac.permissions, 'sales.receivable.follow-up.create');
    const receivables = receivablesQuery.data ?? [];
    const selectedReceivable = receivables.find((receivable) => receivable.id === selectedReceivableId) ?? null;
    const normalizedSearch = deferredSearch.trim().toLowerCase();
    const filteredReceivables = receivables.filter((receivable) => {
        if (normalizedSearch === '') {
            return true;
        }

        return (
            receivable.sale_reference.toLowerCase().includes(normalizedSearch)
            || receivable.customer.name.toLowerCase().includes(normalizedSearch)
            || receivable.customer.document_number.toLowerCase().includes(normalizedSearch)
            || receivable.effective_status.toLowerCase().includes(normalizedSearch)
            || receivable.aging_bucket.toLowerCase().includes(normalizedSearch)
        );
    });

    const aging = agingQuery.data?.summary;
    const openOutstanding = receivables.reduce((carry, receivable) => carry + receivable.outstanding_amount, 0);
    const isInitialLoading = receivablesQuery.isLoading && agingQuery.isLoading && receivables.length === 0;

    const handleRefresh = async () => {
        await Promise.all([
            receivablesQuery.refetch(),
            agingQuery.refetch(),
            selectedReceivableId !== null ? detailQuery.refetch() : Promise.resolve(),
        ]);
    };

    const handleSelectReceivable = (receivableId: number) => {
        startTransition(() => {
            setSelectedReceivableId(receivableId);
        });
    };

    const handleSubmitPayment = async (values: ReceivablePaymentFormData) => {
        if (selectedReceivableId === null) {
            return false;
        }

        paymentMutation.reset();

        try {
            const result = await paymentMutation.mutateAsync({
                receivableId: selectedReceivableId,
                payload: toReceivablePaymentPayload(values),
            });

            toast.success({
                title: 'Cobranza registrada',
                description: `La cuenta quedo en ${result.status} con saldo ${formatCurrency(result.outstanding_amount)}.`,
            });

            return true;
        } catch (error) {
            toast.danger({
                title: 'No pudimos registrar la cobranza',
                description: describeApiError(error),
            });

            return false;
        }
    };

    const handleSubmitFollowUp = async (values: ReceivableFollowUpFormData) => {
        if (selectedReceivableId === null) {
            return false;
        }

        followUpMutation.reset();

        try {
            const result = await followUpMutation.mutateAsync({
                receivableId: selectedReceivableId,
                payload: toReceivableFollowUpPayload(values),
            });

            toast.success({
                title: 'Follow-up registrado',
                description: `Se registro un seguimiento tipo ${result.type} para la cuenta seleccionada.`,
            });

            return true;
        } catch (error) {
            toast.danger({
                title: 'No pudimos registrar el follow-up',
                description: describeApiError(error),
            });

            return false;
        }
    };

    return (
        <div className="space-y-6">
            <PageHeader
                eyebrow="Sales"
                title="Cuentas por cobrar"
                description="El modulo ya consume receivables, aging, detalle, cobranzas y follow-ups reales del backend, manteniendo invalidez de cache y feedback operacional consistente."
                actions={
                    <button
                        type="button"
                        className="rounded-[var(--velmix-radius-md)] border border-[var(--velmix-border-strong)] bg-[var(--velmix-panel-strong)] px-4 py-2 text-sm font-semibold transition hover:border-[var(--velmix-brand)] disabled:cursor-not-allowed disabled:opacity-60"
                        onClick={() => {
                            void handleRefresh();
                        }}
                        disabled={
                            receivablesQuery.isFetching
                            || agingQuery.isFetching
                            || detailQuery.isFetching
                        }
                    >
                        {receivablesQuery.isFetching || agingQuery.isFetching || detailQuery.isFetching
                            ? 'Refrescando...'
                            : 'Refrescar cobranzas'}
                    </button>
                }
            />

            <section className="grid gap-4 xl:grid-cols-5 md:grid-cols-2">
                <MetricCard
                    label="Saldo abierto"
                    value={formatCurrency(openOutstanding)}
                    help="Saldo pendiente agregado de todas las cuentas abiertas."
                />
                <MetricCard
                    label="Al dia"
                    value={aging !== undefined ? formatCurrency(aging.current.amount) : 'N/A'}
                    help={aging !== undefined ? `${formatNumber(aging.current.count)} cuentas` : 'Cargando aging...'}
                />
                <MetricCard
                    label="Mora 1-30"
                    value={aging !== undefined ? formatCurrency(aging.overdue_1_30.amount) : 'N/A'}
                    help={aging !== undefined ? `${formatNumber(aging.overdue_1_30.count)} cuentas` : 'Cargando aging...'}
                />
                <MetricCard
                    label="Mora 31-60"
                    value={aging !== undefined ? formatCurrency(aging.overdue_31_60.amount) : 'N/A'}
                    help={aging !== undefined ? `${formatNumber(aging.overdue_31_60.count)} cuentas` : 'Cargando aging...'}
                />
                <MetricCard
                    label="Mora 61+"
                    value={aging !== undefined ? formatCurrency(aging.overdue_61_plus.amount) : 'N/A'}
                    help={aging !== undefined ? `${formatNumber(aging.overdue_61_plus.count)} cuentas` : 'Cargando aging...'}
                />
            </section>

            {isInitialLoading && (
                <StatePanel
                    tone="neutral"
                    title="Cargando cuentas por cobrar"
                    description="Estamos sincronizando saldos, aging y detalle operativo antes de habilitar cobranza o follow-up."
                />
            )}

            {(receivablesQuery.isError || agingQuery.isError) && (
                <ApiErrorPanel
                    title="No pudimos cargar el cockpit de cobranzas"
                    error={receivablesQuery.isError ? receivablesQuery.error : agingQuery.error}
                    retryLabel="Refrescar cobranzas"
                    onRetry={() => {
                        void handleRefresh();
                    }}
                />
            )}

            <section className="grid gap-6 xl:grid-cols-[minmax(0,1.1fr)_minmax(360px,0.9fr)]">
                <article className="rounded-[var(--velmix-radius-xl)] border border-[var(--velmix-border)] bg-[var(--velmix-panel)] p-6 shadow-[var(--velmix-shadow)]">
                    <div className="flex flex-col gap-4 md:flex-row md:items-end md:justify-between">
                        <div>
                            <p className="text-xs font-semibold uppercase tracking-[0.18em] text-[var(--velmix-brand)]">
                                Backlog de cobranza
                            </p>
                            <h2 className="mt-2 text-2xl font-semibold">Listado de receivables</h2>
                            <p className="mt-2 text-sm leading-6 text-[var(--velmix-muted)]">
                                Filtra por venta, cliente, documento, estado efectivo o bucket de aging. El detalle vive en el panel lateral del mismo workspace.
                            </p>
                        </div>
                        <div className="grid gap-2">
                            <label className="text-xs font-semibold uppercase tracking-[0.16em] text-[var(--velmix-muted)]" htmlFor="receivable-search">
                                Buscar
                            </label>
                            <input
                                id="receivable-search"
                                type="text"
                                value={search}
                                onChange={(event) => setSearch(event.target.value)}
                                className="velmix-input px-3 py-2 text-sm"
                                placeholder="Venta, cliente, documento, estado o aging"
                            />
                        </div>
                    </div>

                    <div className="mt-5 rounded-[var(--velmix-radius-lg)] border border-[var(--velmix-border)] bg-[var(--velmix-panel-strong)] p-4">
                        <ReceivableTable
                            receivables={filteredReceivables}
                            isFetching={receivablesQuery.isFetching}
                            selectedReceivableId={selectedReceivableId}
                            onSelectReceivable={handleSelectReceivable}
                        />
                    </div>
                </article>

                <article className="rounded-[var(--velmix-radius-xl)] border border-[var(--velmix-border)] bg-[var(--velmix-panel)] p-6 shadow-[var(--velmix-shadow)]">
                    <p className="text-xs font-semibold uppercase tracking-[0.18em] text-[var(--velmix-brand)]">
                        Operacion
                    </p>
                    <h2 className="mt-2 text-2xl font-semibold">Estado del modulo</h2>
                    <div className="mt-5 grid gap-3 sm:grid-cols-2">
                        <MiniMetric label="Permiso pay" value={canPay ? 'Activo' : 'No'} />
                        <MiniMetric label="Permiso follow-up" value={canCreateFollowUp ? 'Activo' : 'No'} />
                        <MiniMetric
                            label="Seleccion actual"
                            value={selectedReceivable !== null ? selectedReceivable.sale_reference : 'Ninguna'}
                        />
                        <MiniMetric
                            label="Cuentas visibles"
                            value={formatNumber(filteredReceivables.length)}
                        />
                    </div>
                    <p className="mt-5 text-sm leading-6 text-[var(--velmix-muted)]">
                        Este panel sirve como lectura rapida del workspace. La operacion real vive en el detalle inferior, donde ya puedes cobrar y registrar seguimiento.
                    </p>
                </article>
            </section>

            <ReceivableDetailPanel
                receivable={selectedReceivable}
                detail={detailQuery.data}
                isLoading={detailQuery.isLoading || detailQuery.isFetching}
                isError={detailQuery.isError}
                errorMessage={detailQuery.isError ? describeApiError(detailQuery.error) : null}
                canPay={canPay}
                canCreateFollowUp={canCreateFollowUp}
                paymentErrorMessage={paymentMutation.isError ? describeApiError(paymentMutation.error) : null}
                followUpErrorMessage={followUpMutation.isError ? describeApiError(followUpMutation.error) : null}
                isPaymentPending={paymentMutation.isPending}
                isFollowUpPending={followUpMutation.isPending}
                onRefresh={() => {
                    void detailQuery.refetch();
                }}
                onSubmitPayment={handleSubmitPayment}
                onSubmitFollowUp={handleSubmitFollowUp}
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

interface MiniMetricProps {
    label: string;
    value: string;
}

function MiniMetric({ label, value }: MiniMetricProps) {
    return (
        <div className="velmix-card-strong px-3 py-3">
            <p className="text-[10px] font-black uppercase tracking-[0.16em] text-[var(--velmix-muted)]">{label}</p>
            <p className="mt-1 text-sm font-black">{value}</p>
        </div>
    );
}
