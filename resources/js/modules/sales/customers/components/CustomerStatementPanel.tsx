import { StatePanel } from '@/core/ui/feedback/StatePanel';
import { StatusBadge, type StatusBadgeTone } from '@/shared/components/StatusBadge';
import { formatCurrency, formatDate, formatDateTime, formatNumber } from '@/shared/utils/formatters';
import type { CustomerStatement, SalesCustomer } from '@/modules/sales/customers/types';

interface CustomerStatementPanelProps {
    customer: SalesCustomer | null;
    statement: CustomerStatement | undefined;
    isLoading: boolean;
    isError: boolean;
    errorMessage: string | null;
    onRefresh: () => void;
}

export function CustomerStatementPanel({
    customer,
    statement,
    isLoading,
    isError,
    errorMessage,
    onRefresh,
}: CustomerStatementPanelProps) {
    if (customer === null) {
        return (
            <StatePanel
                tone="neutral"
                title="Selecciona un cliente"
                description="Desde la tabla puedes abrir el estado de cuenta comercial, revisar pagos, receivables y seguimiento de cobranza."
            />
        );
    }

    if (isLoading && statement === undefined) {
        return (
            <StatePanel
                tone="neutral"
                title="Cargando estado de cuenta"
                description={`Estamos consultando el historial comercial de ${customer.name} para el tenant activo.`}
            />
        );
    }

    if (isError) {
        return (
            <StatePanel
                tone="danger"
                title="No pudimos cargar el estado de cuenta"
                description={errorMessage ?? 'Ocurrio un problema inesperado al consultar el estado de cuenta del cliente.'}
            />
        );
    }

    if (statement === undefined) {
        return null;
    }

    return (
        <section className="rounded-[var(--velmix-radius-xl)] border border-[var(--velmix-border)] bg-[var(--velmix-panel)] p-6 shadow-[var(--velmix-shadow)]">
            <div className="flex flex-col gap-4 lg:flex-row lg:items-start lg:justify-between">
                <div>
                    <p className="text-xs font-semibold uppercase tracking-[0.18em] text-[var(--velmix-brand)]">
                        Cuenta comercial
                    </p>
                    <h2 className="mt-2 text-2xl font-semibold">{statement.customer.name}</h2>
                    <p className="mt-2 text-sm leading-6 text-[var(--velmix-muted)]">
                        Documento {statement.customer.document_type.toUpperCase()} {statement.customer.document_number}.
                        Credito disponible {statement.summary.available_credit !== null
                            ? ` ${formatCurrency(statement.summary.available_credit)}.`
                            : ' no configurado.'}
                    </p>
                </div>

                <div className="flex flex-wrap items-center gap-3">
                    <StatusBadge label={statement.customer.status} tone={toneForCustomerStatus(statement.customer.status)} />
                    <button
                        type="button"
                        className="rounded-[var(--velmix-radius-md)] border border-[var(--velmix-border-strong)] px-4 py-2 text-sm font-semibold transition hover:border-[var(--velmix-brand)]"
                        onClick={onRefresh}
                    >
                        Refrescar cuenta
                    </button>
                </div>
            </div>

            <section className="mt-5 grid gap-4 xl:grid-cols-5 md:grid-cols-2">
                <StatementMetric label="Ventas" value={formatCurrency(statement.summary.sales_total)} />
                <StatementMetric label="Receivables" value={formatCurrency(statement.summary.receivables_total)} />
                <StatementMetric label="Pagos" value={formatCurrency(statement.summary.payments_total)} />
                <StatementMetric label="Saldo" value={formatCurrency(statement.summary.outstanding_total)} />
                <StatementMetric label="Follow-ups" value={formatNumber(statement.summary.follow_up_count)} />
            </section>

            <section className="mt-5 grid gap-6 xl:grid-cols-[minmax(0,1.2fr)_minmax(0,0.8fr)]">
                <article className="rounded-[var(--velmix-radius-lg)] border border-[var(--velmix-border)] bg-[var(--velmix-panel-strong)] p-4">
                    <p className="text-sm font-semibold uppercase tracking-[0.16em] text-[var(--velmix-muted)]">
                        Receivables
                    </p>
                    <div className="mt-3 overflow-x-auto">
                        <table className="min-w-full text-left text-sm">
                            <thead className="text-[var(--velmix-muted)]">
                                <tr>
                                    <th className="px-3 py-2 font-semibold">Venta</th>
                                    <th className="px-3 py-2 font-semibold">Estado</th>
                                    <th className="px-3 py-2 font-semibold">Vence</th>
                                    <th className="px-3 py-2 font-semibold">Saldo</th>
                                </tr>
                            </thead>
                            <tbody>
                                {statement.receivables.length === 0 ? (
                                    <tr>
                                        <td className="px-3 py-4 text-[var(--velmix-muted)]" colSpan={4}>
                                            Sin receivables registrados para este cliente.
                                        </td>
                                    </tr>
                                ) : (
                                    statement.receivables.map((receivable) => (
                                        <tr key={receivable.id} className="border-t border-[var(--velmix-border)]">
                                            <td className="px-3 py-3 font-semibold">{receivable.sale_reference}</td>
                                            <td className="px-3 py-3">
                                                <StatusBadge label={receivable.status} tone={toneForReceivableStatus(receivable.status)} />
                                            </td>
                                            <td className="px-3 py-3">{formatDate(receivable.due_at)}</td>
                                            <td className="px-3 py-3">{formatCurrency(receivable.outstanding_amount)}</td>
                                        </tr>
                                    ))
                                )}
                            </tbody>
                        </table>
                    </div>
                </article>

                <article className="rounded-[var(--velmix-radius-lg)] border border-[var(--velmix-border)] bg-[var(--velmix-panel-strong)] p-4">
                    <p className="text-sm font-semibold uppercase tracking-[0.16em] text-[var(--velmix-muted)]">
                        Politica comercial
                    </p>
                    <dl className="mt-3 grid gap-3 text-sm text-[var(--velmix-muted)]">
                        <MetricRow
                            label="Limite de credito"
                            value={
                                statement.customer.credit_limit !== null
                                    ? formatCurrency(statement.customer.credit_limit)
                                    : 'Sin limite configurado'
                            }
                        />
                        <MetricRow
                            label="Dias de credito"
                            value={
                                statement.customer.credit_days !== null
                                    ? `${statement.customer.credit_days} dias`
                                    : 'Sin dias configurados'
                            }
                        />
                        <MetricRow
                            label="Uso del credito"
                            value={
                                statement.summary.credit_utilization_pct !== null
                                    ? `${formatNumber(statement.summary.credit_utilization_pct)}%`
                                    : 'N/A'
                            }
                        />
                        <MetricRow
                            label="Bloqueo por mora"
                            value={statement.customer.block_on_overdue ? 'Activo' : 'No aplica'}
                        />
                        <MetricRow
                            label="Receivables vencidos"
                            value={formatNumber(statement.summary.overdue_receivable_count)}
                        />
                        <MetricRow
                            label="Promesas de pago"
                            value={formatNumber(statement.summary.promised_follow_up_count)}
                        />
                    </dl>
                </article>
            </section>

            <section className="mt-6 grid gap-6 xl:grid-cols-2">
                <article className="rounded-[var(--velmix-radius-lg)] border border-[var(--velmix-border)] bg-[var(--velmix-panel-strong)] p-4">
                    <p className="text-sm font-semibold uppercase tracking-[0.16em] text-[var(--velmix-muted)]">
                        Pagos recientes
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
                                {statement.payments.length === 0 ? (
                                    <tr>
                                        <td className="px-3 py-4 text-[var(--velmix-muted)]" colSpan={4}>
                                            Todavia no hay pagos registrados.
                                        </td>
                                    </tr>
                                ) : (
                                    statement.payments.slice(0, 6).map((payment) => (
                                        <tr key={payment.id} className="border-t border-[var(--velmix-border)]">
                                            <td className="px-3 py-3">
                                                <p className="font-semibold">{payment.reference}</p>
                                                <p className="text-[var(--velmix-muted)]">{payment.sale_reference}</p>
                                            </td>
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
                        Follow-ups de cobranza
                    </p>
                    <div className="mt-3 overflow-x-auto">
                        <table className="min-w-full text-left text-sm">
                            <thead className="text-[var(--velmix-muted)]">
                                <tr>
                                    <th className="px-3 py-2 font-semibold">Tipo</th>
                                    <th className="px-3 py-2 font-semibold">Detalle</th>
                                    <th className="px-3 py-2 font-semibold">Promesa</th>
                                </tr>
                            </thead>
                            <tbody>
                                {statement.follow_ups.length === 0 ? (
                                    <tr>
                                        <td className="px-3 py-4 text-[var(--velmix-muted)]" colSpan={3}>
                                            No existen gestiones de cobranza todavia.
                                        </td>
                                    </tr>
                                ) : (
                                    statement.follow_ups.slice(0, 6).map((followUp) => (
                                        <tr key={followUp.id} className="border-t border-[var(--velmix-border)] align-top">
                                            <td className="px-3 py-3">
                                                <StatusBadge label={followUp.type} tone={toneForFollowUpType(followUp.type)} />
                                            </td>
                                            <td className="px-3 py-3">
                                                <p className="font-semibold">{followUp.sale_reference}</p>
                                                <p className="mt-1 text-[var(--velmix-muted)]">{followUp.note}</p>
                                                <p className="mt-1 text-xs uppercase tracking-[0.14em] text-[var(--velmix-muted)]">
                                                    {followUp.user.name} · {formatDateTime(followUp.created_at)}
                                                </p>
                                            </td>
                                            <td className="px-3 py-3">
                                                <p>{followUp.promised_amount !== null ? formatCurrency(followUp.promised_amount) : 'N/A'}</p>
                                                <p className="text-[var(--velmix-muted)]">{formatDate(followUp.promised_at)}</p>
                                            </td>
                                        </tr>
                                    ))
                                )}
                            </tbody>
                        </table>
                    </div>
                </article>
            </section>
        </section>
    );
}

interface StatementMetricProps {
    label: string;
    value: string;
}

function StatementMetric({ label, value }: StatementMetricProps) {
    return (
        <article className="rounded-[var(--velmix-radius-lg)] border border-[var(--velmix-border)] bg-[var(--velmix-panel-strong)] p-4">
            <p className="text-xs font-semibold uppercase tracking-[0.16em] text-[var(--velmix-muted)]">{label}</p>
            <p className="mt-2 text-xl font-semibold">{value}</p>
        </article>
    );
}

interface MetricRowProps {
    label: string;
    value: string;
}

function MetricRow({ label, value }: MetricRowProps) {
    return (
        <div className="grid gap-1">
            <dt className="font-semibold text-[var(--velmix-ink)]">{label}</dt>
            <dd>{value}</dd>
        </div>
    );
}

function toneForCustomerStatus(status: string): StatusBadgeTone {
    if (status === 'active') {
        return 'success';
    }

    if (status === 'inactive') {
        return 'warning';
    }

    return 'neutral';
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

function toneForFollowUpType(type: string): StatusBadgeTone {
    if (type === 'promise') {
        return 'warning';
    }

    if (type === 'paid' || type === 'resolved') {
        return 'success';
    }

    return 'info';
}
