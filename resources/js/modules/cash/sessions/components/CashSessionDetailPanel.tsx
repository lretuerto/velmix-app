import { StatePanel } from '@/core/ui/feedback/StatePanel';
import { StatusBadge, type StatusBadgeTone } from '@/shared/components/StatusBadge';
import { formatCurrency, formatDateTime, formatNumber } from '@/shared/utils/formatters';
import type { CashMovement, CashSessionSummary } from '@/modules/cash/sessions/types';

interface CashSessionDetailPanelProps {
    session: CashSessionSummary | null;
    detail: CashSessionSummary | undefined;
    movements: CashMovement[] | undefined;
    isDetailLoading: boolean;
    isMovementsLoading: boolean;
    isDetailError: boolean;
    detailErrorMessage: string | null;
    movementsErrorMessage: string | null;
    canReadMovements: boolean;
    onRefresh: () => void;
}

export function CashSessionDetailPanel({
    session,
    detail,
    movements,
    isDetailLoading,
    isMovementsLoading,
    isDetailError,
    detailErrorMessage,
    movementsErrorMessage,
    canReadMovements,
    onRefresh,
}: CashSessionDetailPanelProps) {
    if (session === null) {
        return (
            <StatePanel
                tone="neutral"
                title="Selecciona una sesion de caja"
                description="Desde el historial puedes abrir el detalle de una caja, revisar profitability, denominaciones y movimientos manuales."
            />
        );
    }

    if (isDetailLoading && detail === undefined) {
        return (
            <StatePanel
                tone="neutral"
                title="Cargando detalle de caja"
                description={`Estamos consultando la sesion #${session.id} del tenant activo.`}
            />
        );
    }

    if (isDetailError) {
        return (
            <StatePanel
                tone="danger"
                title="No pudimos cargar el detalle de caja"
                description={detailErrorMessage ?? 'Ocurrio un problema inesperado al consultar la caja seleccionada.'}
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
                        Detalle de caja
                    </p>
                    <h2 className="mt-2 text-2xl font-semibold">Sesion #{detail.id}</h2>
                    <p className="mt-2 text-sm leading-6 text-[var(--velmix-muted)]">
                        Apertura por {detail.opened_by.name ?? 'N/A'} · cierre por {detail.closed_by?.name ?? 'Pendiente'}.
                    </p>
                </div>

                <div className="flex flex-wrap items-center gap-3">
                    <StatusBadge label={detail.status} tone={toneForCashStatus(detail.status)} />
                    <button
                        type="button"
                        className="rounded-[var(--velmix-radius-md)] border border-[var(--velmix-border-strong)] px-4 py-2 text-sm font-semibold transition hover:border-[var(--velmix-brand)]"
                        onClick={onRefresh}
                    >
                        Refrescar caja
                    </button>
                </div>
            </div>

            <section className="mt-5 grid gap-4 xl:grid-cols-5 md:grid-cols-2">
                <MetricCard label="Opening" value={formatCurrency(detail.opening_amount)} />
                <MetricCard label="Expected" value={formatCurrency(detail.expected_amount)} />
                <MetricCard
                    label="Counted"
                    value={detail.counted_amount !== null ? formatCurrency(detail.counted_amount) : 'Pendiente'}
                />
                <MetricCard
                    label="Discrepancy"
                    value={detail.discrepancy_amount !== null ? formatCurrency(detail.discrepancy_amount) : 'Pendiente'}
                />
                <MetricCard label="Ventas" value={formatNumber(detail.sales_count)} />
            </section>

            <section className="mt-5 grid gap-6 xl:grid-cols-[minmax(0,1.1fr)_minmax(0,0.9fr)]">
                <article className="rounded-[var(--velmix-radius-lg)] border border-[var(--velmix-border)] bg-[var(--velmix-panel-strong)] p-4">
                    <p className="text-sm font-semibold uppercase tracking-[0.16em] text-[var(--velmix-muted)]">
                        Resumen operativo
                    </p>
                    <div className="mt-4 grid gap-3 md:grid-cols-2">
                        <MetricRow label="Sales total" value={formatCurrency(detail.sales_total)} />
                        <MetricRow label="Cash sales" value={formatCurrency(detail.cash_sales_total)} />
                        <MetricRow label="Card sales" value={formatCurrency(detail.card_sales_total)} />
                        <MetricRow label="Transfer sales" value={formatCurrency(detail.transfer_sales_total)} />
                        <MetricRow label="Credit sales" value={formatCurrency(detail.credit_sales_total)} />
                        <MetricRow label="Receivable cash" value={formatCurrency(detail.receivable_cash_total)} />
                        <MetricRow label="Manual in" value={formatCurrency(detail.manual_in_total)} />
                        <MetricRow label="Manual out" value={formatCurrency(detail.manual_out_total)} />
                        <MetricRow label="Refund out" value={formatCurrency(detail.refund_out_total)} />
                        <MetricRow label="Net movement" value={formatCurrency(detail.net_movement_total)} />
                        <MetricRow label="Gross cost" value={formatCurrency(detail.gross_cost_total)} />
                        <MetricRow label="Gross margin" value={formatCurrency(detail.gross_margin_total)} />
                    </div>
                    <p className="mt-4 text-sm text-[var(--velmix-muted)]">
                        Margen de la sesion: {formatNumber(detail.margin_pct)}%
                    </p>
                </article>

                <article className="rounded-[var(--velmix-radius-lg)] border border-[var(--velmix-border)] bg-[var(--velmix-panel-strong)] p-4">
                    <p className="text-sm font-semibold uppercase tracking-[0.16em] text-[var(--velmix-muted)]">
                        Trazabilidad
                    </p>
                    <dl className="mt-3 grid gap-3 text-sm text-[var(--velmix-muted)]">
                        <MetricRow label="Apertura" value={formatDateTime(detail.opened_at)} />
                        <MetricRow label="Cierre" value={formatDateTime(detail.closed_at)} />
                        <MetricRow label="Opened by" value={detail.opened_by.name ?? 'N/A'} />
                        <MetricRow label="Closed by" value={detail.closed_by?.name ?? 'Pendiente'} />
                        <MetricRow label="Movement count" value={formatNumber(detail.movement_count)} />
                    </dl>

                    {detail.denominations !== undefined && detail.denominations.length > 0 && (
                        <div className="mt-4 rounded-[var(--velmix-radius-md)] border border-[var(--velmix-border)] bg-white/70 p-3">
                            <p className="text-xs font-semibold uppercase tracking-[0.16em] text-[var(--velmix-muted)]">
                                Denominaciones
                            </p>
                            <ul className="mt-3 space-y-2 text-sm text-[var(--velmix-muted)]">
                                {detail.denominations.map((denomination) => (
                                    <li key={`${denomination.value}-${denomination.quantity}`}>
                                        {formatCurrency(denomination.value)} x {denomination.quantity} = {formatCurrency(denomination.subtotal)}
                                    </li>
                                ))}
                            </ul>
                        </div>
                    )}
                </article>
            </section>

            <article className="mt-6 rounded-[var(--velmix-radius-lg)] border border-[var(--velmix-border)] bg-[var(--velmix-panel-strong)] p-4">
                <p className="text-sm font-semibold uppercase tracking-[0.16em] text-[var(--velmix-muted)]">
                    Movimientos de caja
                </p>
                {!canReadMovements ? (
                    <p className="mt-3 text-sm leading-6 text-[var(--velmix-muted)]">
                        El usuario actual no tiene el permiso `cash.movement.read` para listar movimientos.
                    </p>
                ) : isMovementsLoading && movements === undefined ? (
                    <p className="mt-3 text-sm leading-6 text-[var(--velmix-muted)]">
                        Cargando movimientos de la sesion seleccionada...
                    </p>
                ) : movementsErrorMessage !== null ? (
                    <p className="mt-3 text-sm leading-6 text-rose-800">{movementsErrorMessage}</p>
                ) : (
                    <div className="mt-3 overflow-x-auto">
                        <table className="min-w-full text-left text-sm">
                            <thead className="text-[var(--velmix-muted)]">
                                <tr>
                                    <th className="px-3 py-2 font-semibold">Tipo</th>
                                    <th className="px-3 py-2 font-semibold">Referencia</th>
                                    <th className="px-3 py-2 font-semibold">Monto</th>
                                    <th className="px-3 py-2 font-semibold">Fecha</th>
                                </tr>
                            </thead>
                            <tbody>
                                {(movements ?? []).length === 0 ? (
                                    <tr>
                                        <td className="px-3 py-4 text-[var(--velmix-muted)]" colSpan={4}>
                                            No hay movimientos registrados para esta caja.
                                        </td>
                                    </tr>
                                ) : (
                                    (movements ?? []).map((movement) => (
                                        <tr key={movement.id} className="border-t border-[var(--velmix-border)]">
                                            <td className="px-3 py-3">
                                                <StatusBadge label={movement.type} tone={toneForMovementType(movement.type)} />
                                            </td>
                                            <td className="px-3 py-3">
                                                <p className="font-semibold">{movement.reference}</p>
                                                <p className="text-[var(--velmix-muted)]">{movement.notes ?? 'Sin notas'}</p>
                                            </td>
                                            <td className="px-3 py-3">{formatCurrency(movement.amount)}</td>
                                            <td className="px-3 py-3">{formatDateTime(movement.created_at)}</td>
                                        </tr>
                                    ))
                                )}
                            </tbody>
                        </table>
                    </div>
                )}
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

function toneForCashStatus(status: string): StatusBadgeTone {
    if (status === 'open') {
        return 'success';
    }

    if (status === 'closed') {
        return 'info';
    }

    return 'neutral';
}

function toneForMovementType(type: string): StatusBadgeTone {
    if (type === 'manual_in' || type === 'receivable_in') {
        return 'success';
    }

    if (type === 'manual_out' || type === 'credit_note_refund') {
        return 'warning';
    }

    return 'info';
}
