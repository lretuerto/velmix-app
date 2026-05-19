import { StatePanel } from '@/core/ui/feedback/StatePanel';
import { ReceivableFollowUpForm } from '@/modules/sales/receivables/components/ReceivableFollowUpForm';
import { ReceivablePaymentForm } from '@/modules/sales/receivables/components/ReceivablePaymentForm';
import type {
    ReceivableFollowUpFormData,
    ReceivablePaymentFormData,
} from '@/modules/sales/receivables/schema';
import type { SaleReceivableDetail, SaleReceivableSummary } from '@/modules/sales/receivables/types';
import { StatusBadge, type StatusBadgeTone } from '@/shared/components/StatusBadge';
import { formatCurrency, formatDate, formatDateTime } from '@/shared/utils/formatters';

interface ReceivableDetailPanelProps {
    receivable: SaleReceivableSummary | null;
    detail: SaleReceivableDetail | undefined;
    isLoading: boolean;
    isError: boolean;
    errorMessage: string | null;
    canPay: boolean;
    canCreateFollowUp: boolean;
    paymentErrorMessage: string | null;
    followUpErrorMessage: string | null;
    isPaymentPending: boolean;
    isFollowUpPending: boolean;
    onRefresh: () => void;
    onSubmitPayment: (values: ReceivablePaymentFormData) => Promise<boolean>;
    onSubmitFollowUp: (values: ReceivableFollowUpFormData) => Promise<boolean>;
}

export function ReceivableDetailPanel({
    receivable,
    detail,
    isLoading,
    isError,
    errorMessage,
    canPay,
    canCreateFollowUp,
    paymentErrorMessage,
    followUpErrorMessage,
    isPaymentPending,
    isFollowUpPending,
    onRefresh,
    onSubmitPayment,
    onSubmitFollowUp,
}: ReceivableDetailPanelProps) {
    if (receivable === null) {
        return (
            <StatePanel
                tone="neutral"
                title="Selecciona una cuenta por cobrar"
                description="Desde la tabla puedes abrir el detalle, registrar cobranza o dejar follow-ups de cobranza sobre un documento especifico."
            />
        );
    }

    if (isLoading && detail === undefined) {
        return (
            <StatePanel
                tone="neutral"
                title="Cargando detalle de cobranza"
                description={`Estamos consultando la cuenta ${receivable.sale_reference} para el tenant activo.`}
            />
        );
    }

    if (isError) {
        return (
            <StatePanel
                tone="danger"
                title="No pudimos cargar el detalle de la cuenta"
                description={errorMessage ?? 'Ocurrio un problema inesperado al consultar el detalle de la cobranza.'}
            />
        );
    }

    if (detail === undefined) {
        return null;
    }

    return (
        <section className="rounded-[var(--velmix-radius-xl)] border border-[var(--velmix-border)] bg-[var(--velmix-panel)] p-6 shadow-[var(--velmix-shadow)]">
            <div className="flex flex-col gap-4 lg:flex-row lg:items-start lg:justify-between">
                <div>
                    <p className="text-xs font-semibold uppercase tracking-[0.18em] text-[var(--velmix-brand)]">
                        Detalle de cobranza
                    </p>
                    <h2 className="mt-2 text-2xl font-semibold">{detail.sale.reference}</h2>
                    <p className="mt-2 text-sm leading-6 text-[var(--velmix-muted)]">
                        Cliente {detail.customer.name} · {detail.customer.document_type.toUpperCase()} {detail.customer.document_number}.
                    </p>
                </div>

                <div className="flex flex-wrap items-center gap-3">
                    <StatusBadge label={detail.status} tone={toneForReceivableStatus(detail.status)} />
                    <StatusBadge label={detail.effective_status} tone={toneForEffectiveStatus(detail.effective_status)} />
                    <button
                        type="button"
                        className="rounded-[var(--velmix-radius-md)] border border-[var(--velmix-border-strong)] px-4 py-2 text-sm font-semibold transition hover:border-[var(--velmix-brand)]"
                        onClick={onRefresh}
                    >
                        Refrescar detalle
                    </button>
                </div>
            </div>

            <section className="mt-5 grid gap-4 xl:grid-cols-4 md:grid-cols-2">
                <MetricCard label="Total" value={formatCurrency(detail.total_amount)} />
                <MetricCard label="Pagado" value={formatCurrency(detail.paid_amount)} />
                <MetricCard label="Saldo" value={formatCurrency(detail.outstanding_amount)} />
                <MetricCard label="Vence" value={formatDate(detail.due_at)} />
            </section>

            <section className="mt-5 grid gap-6 xl:grid-cols-[minmax(0,1.15fr)_minmax(0,0.85fr)]">
                <article className="rounded-[var(--velmix-radius-lg)] border border-[var(--velmix-border)] bg-[var(--velmix-panel-strong)] p-4">
                    <p className="text-sm font-semibold uppercase tracking-[0.16em] text-[var(--velmix-muted)]">
                        Historial de pagos
                    </p>
                    <div className="mt-3 overflow-x-auto">
                        <table className="min-w-full text-left text-sm">
                            <thead className="text-[var(--velmix-muted)]">
                                <tr>
                                    <th className="px-3 py-2 font-semibold">Referencia</th>
                                    <th className="px-3 py-2 font-semibold">Metodo</th>
                                    <th className="px-3 py-2 font-semibold">Monto</th>
                                    <th className="px-3 py-2 font-semibold">Fecha</th>
                                </tr>
                            </thead>
                            <tbody>
                                {detail.payments.length === 0 ? (
                                    <tr>
                                        <td className="px-3 py-4 text-[var(--velmix-muted)]" colSpan={4}>
                                            Todavia no hay pagos registrados para esta cuenta.
                                        </td>
                                    </tr>
                                ) : (
                                    detail.payments.map((payment) => (
                                        <tr key={payment.id} className="border-t border-[var(--velmix-border)]">
                                            <td className="px-3 py-3 font-semibold">{payment.reference}</td>
                                            <td className="px-3 py-3">{payment.payment_method}</td>
                                            <td className="px-3 py-3">{formatCurrency(payment.amount)}</td>
                                            <td className="px-3 py-3">{formatDateTime(payment.paid_at)}</td>
                                        </tr>
                                    ))
                                )}
                            </tbody>
                        </table>
                    </div>
                </article>

                <article className="rounded-[var(--velmix-radius-lg)] border border-[var(--velmix-border)] bg-[var(--velmix-panel-strong)] p-4">
                    <p className="text-sm font-semibold uppercase tracking-[0.16em] text-[var(--velmix-muted)]">
                        Ultimo follow-up
                    </p>
                    {detail.latest_follow_up === null ? (
                        <p className="mt-3 text-sm leading-6 text-[var(--velmix-muted)]">
                            Aun no se registran gestiones de cobranza para esta cuenta.
                        </p>
                    ) : (
                        <div className="mt-3 space-y-3 text-sm text-[var(--velmix-muted)]">
                            <div className="flex items-center gap-3">
                                <StatusBadge
                                    label={detail.latest_follow_up.type}
                                    tone={toneForFollowUpType(detail.latest_follow_up.type)}
                                />
                                <span>{detail.latest_follow_up.user.name}</span>
                            </div>
                            <p className="leading-6">{detail.latest_follow_up.note}</p>
                            <p>
                                Promesa: {detail.latest_follow_up.promised_amount !== null
                                    ? formatCurrency(detail.latest_follow_up.promised_amount)
                                    : 'N/A'}
                            </p>
                            <p>Fecha comprometida: {formatDate(detail.latest_follow_up.promised_at)}</p>
                            <p>Registrado: {formatDateTime(detail.latest_follow_up.created_at)}</p>
                        </div>
                    )}
                </article>
            </section>

            <section className="mt-6 grid gap-6 xl:grid-cols-2">
                <article className="rounded-[var(--velmix-radius-lg)] border border-[var(--velmix-border)] bg-[var(--velmix-panel-strong)] p-4">
                    <p className="text-sm font-semibold uppercase tracking-[0.16em] text-[var(--velmix-muted)]">
                        Registrar cobranza
                    </p>
                    <div className="mt-4">
                        {canPay ? (
                            <ReceivablePaymentForm
                                isPending={isPaymentPending}
                                errorMessage={paymentErrorMessage}
                                onSubmit={onSubmitPayment}
                            />
                        ) : (
                            <StatePanel
                                tone="warning"
                                title="Acceso de solo lectura"
                                description="El usuario actual puede revisar receivables, pero no tiene el permiso `sales.receivable.pay` para registrar cobranza."
                            />
                        )}
                    </div>
                </article>

                <article className="rounded-[var(--velmix-radius-lg)] border border-[var(--velmix-border)] bg-[var(--velmix-panel-strong)] p-4">
                    <p className="text-sm font-semibold uppercase tracking-[0.16em] text-[var(--velmix-muted)]">
                        Registrar follow-up
                    </p>
                    <div className="mt-4">
                        {canCreateFollowUp ? (
                            <ReceivableFollowUpForm
                                isPending={isFollowUpPending}
                                errorMessage={followUpErrorMessage}
                                onSubmit={onSubmitFollowUp}
                            />
                        ) : (
                            <StatePanel
                                tone="warning"
                                title="Sin permiso de seguimiento"
                                description="El usuario actual no tiene el permiso `sales.receivable.follow-up.create` para registrar notas o promesas de cobro."
                            />
                        )}
                    </div>
                </article>
            </section>

            <article className="mt-6 rounded-[var(--velmix-radius-lg)] border border-[var(--velmix-border)] bg-[var(--velmix-panel-strong)] p-4">
                <p className="text-sm font-semibold uppercase tracking-[0.16em] text-[var(--velmix-muted)]">
                    Historial de follow-ups
                </p>
                <div className="mt-3 overflow-x-auto">
                    <table className="min-w-full text-left text-sm">
                        <thead className="text-[var(--velmix-muted)]">
                            <tr>
                                <th className="px-3 py-2 font-semibold">Tipo</th>
                                <th className="px-3 py-2 font-semibold">Nota</th>
                                <th className="px-3 py-2 font-semibold">Promesa</th>
                                <th className="px-3 py-2 font-semibold">Registrado</th>
                            </tr>
                        </thead>
                        <tbody>
                            {detail.follow_ups.length === 0 ? (
                                <tr>
                                    <td className="px-3 py-4 text-[var(--velmix-muted)]" colSpan={4}>
                                        No existen follow-ups registrados para esta cuenta.
                                    </td>
                                </tr>
                            ) : (
                                detail.follow_ups.map((followUp) => (
                                    <tr key={followUp.id} className="border-t border-[var(--velmix-border)] align-top">
                                        <td className="px-3 py-3">
                                            <StatusBadge label={followUp.type} tone={toneForFollowUpType(followUp.type)} />
                                        </td>
                                        <td className="px-3 py-3">
                                            <p className="font-semibold">{followUp.user.name}</p>
                                            <p className="mt-1 text-[var(--velmix-muted)]">{followUp.note}</p>
                                        </td>
                                        <td className="px-3 py-3">
                                            <p>{followUp.promised_amount !== null ? formatCurrency(followUp.promised_amount) : 'N/A'}</p>
                                            <p className="text-[var(--velmix-muted)]">{formatDate(followUp.promised_at)}</p>
                                        </td>
                                        <td className="px-3 py-3">{formatDateTime(followUp.created_at)}</td>
                                    </tr>
                                ))
                            )}
                        </tbody>
                    </table>
                </div>
            </article>
        </section>
    );
}

interface MetricCardProps {
    label: string;
    value: string;
}

function MetricCard({ label, value }: MetricCardProps) {
    return (
        <article className="velmix-card-strong p-4">
            <p className="text-[10px] font-black uppercase tracking-[0.16em] text-[var(--velmix-muted)]">{label}</p>
            <p className="mt-2 text-xl font-black tracking-[-0.04em]">{value}</p>
        </article>
    );
}

function toneForReceivableStatus(status: string): StatusBadgeTone {
    if (status === 'paid') {
        return 'success';
    }

    if (status === 'partial_paid') {
        return 'warning';
    }

    if (status === 'pending') {
        return 'info';
    }

    return 'neutral';
}

function toneForEffectiveStatus(status: string): StatusBadgeTone {
    if (status === 'overdue') {
        return 'danger';
    }

    return toneForReceivableStatus(status);
}

function toneForFollowUpType(type: string): StatusBadgeTone {
    if (type === 'promise') {
        return 'warning';
    }

    return 'info';
}
