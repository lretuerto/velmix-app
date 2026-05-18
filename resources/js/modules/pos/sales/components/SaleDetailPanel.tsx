import { StatePanel } from '@/core/ui/feedback/StatePanel';
import { StatusBadge, type StatusBadgeTone } from '@/shared/components/StatusBadge';
import { formatCurrency, formatDateTime } from '@/shared/utils/formatters';
import type { PosSaleDetail, PosSaleSummary } from '@/modules/pos/sales/types';

interface SaleDetailPanelProps {
    sale: PosSaleSummary | null;
    detail: PosSaleDetail | undefined;
    isLoading: boolean;
    isError: boolean;
    errorMessage: string | null;
    onRefresh: () => void;
}

export function SaleDetailPanel({
    sale,
    detail,
    isLoading,
    isError,
    errorMessage,
    onRefresh,
}: SaleDetailPanelProps) {
    if (sale === null) {
        return (
            <StatePanel
                tone="neutral"
                title="Selecciona una venta"
                description="Desde la tabla puedes abrir el detalle de la venta, revisar voucher, cuenta por cobrar asociada y credit notes si existen."
            />
        );
    }

    if (isLoading && detail === undefined) {
        return (
            <StatePanel
                tone="neutral"
                title="Cargando detalle de venta"
                description={`Estamos consultando la venta ${sale.reference} para el tenant activo.`}
            />
        );
    }

    if (isError) {
        return (
            <StatePanel
                tone="danger"
                title="No pudimos cargar el detalle de la venta"
                description={errorMessage ?? 'Ocurrio un problema inesperado al leer el detalle de la venta.'}
            />
        );
    }

    if (detail === undefined) {
        return null;
    }

    return (
        <section className="velmix-card overflow-hidden">
            <div className="flex flex-col gap-4 border-b border-[var(--velmix-border)] bg-white/70 px-6 py-5 lg:flex-row lg:items-start lg:justify-between">
                <div>
                    <p className="text-[11px] font-black uppercase tracking-[0.22em] text-[var(--velmix-brand)]">
                        Detalle POS
                    </p>
                    <h2 className="mt-2 text-3xl font-black tracking-[-0.05em]">{detail.reference}</h2>
                    <p className="mt-2 text-sm leading-6 text-[var(--velmix-muted)]">
                        {detail.customer !== null
                            ? `Cliente ${detail.customer.name} · ${detail.customer.document_number}.`
                            : 'Venta mostrador sin cliente asociado.'}
                    </p>
                </div>

                <div className="flex flex-wrap items-center gap-3">
                    <StatusBadge label={detail.status} tone={toneForSaleStatus(detail.status)} />
                    {detail.voucher !== null && (
                        <StatusBadge label={detail.voucher.status} tone={toneForVoucherStatus(detail.voucher.status)} />
                    )}
                    <button
                        type="button"
                        className="velmix-button-secondary px-4 py-2 text-sm"
                        onClick={onRefresh}
                    >
                        Refrescar detalle
                    </button>
                </div>
            </div>

            <div className="px-6 py-5">
            <section className="grid gap-3 xl:grid-cols-4 md:grid-cols-2">
                <MetricCard label="Total" value={formatCurrency(detail.total_amount)} />
                <MetricCard label="Costo" value={formatCurrency(detail.gross_cost)} />
                <MetricCard label="Margen" value={formatCurrency(detail.gross_margin)} />
                <MetricCard label="Movimientos stock" value={String(detail.movement_count)} />
            </section>

            <section className="mt-5 grid gap-5 xl:grid-cols-[minmax(0,1.2fr)_minmax(0,0.8fr)]">
                <article className="velmix-card-strong p-4">
                    <p className="text-sm font-black uppercase tracking-[0.16em] text-[var(--velmix-muted)]">
                        Items vendidos
                    </p>
                    <div className="mt-3 overflow-x-auto">
                        <table className="min-w-full text-left text-sm">
                            <thead className="text-[var(--velmix-muted)]">
                                <tr>
                                    <th className="px-3 py-2 font-semibold">Producto</th>
                                    <th className="px-3 py-2 font-semibold">Lote</th>
                                    <th className="px-3 py-2 font-semibold">Cantidad</th>
                                    <th className="px-3 py-2 font-semibold">Precio</th>
                                    <th className="px-3 py-2 font-semibold">Linea</th>
                                </tr>
                            </thead>
                            <tbody>
                                {detail.items.map((item) => (
                                    <tr key={item.id} className="border-t border-[var(--velmix-border)]">
                                        <td className="px-3 py-3">
                                            <p className="font-semibold">{item.product_sku}</p>
                                            {(item.prescription_code !== null || item.approval_code !== null) && (
                                                <p className="text-[var(--velmix-muted)]">
                                                    {item.prescription_code ?? item.approval_code}
                                                </p>
                                            )}
                                        </td>
                                        <td className="px-3 py-3">{item.lot_code}</td>
                                        <td className="px-3 py-3">{item.quantity}</td>
                                        <td className="px-3 py-3">{formatCurrency(item.unit_price)}</td>
                                        <td className="px-3 py-3">{formatCurrency(item.line_total)}</td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    </div>
                </article>

                <article className="velmix-card-strong p-4">
                    <p className="text-sm font-black uppercase tracking-[0.16em] text-[var(--velmix-muted)]">
                        Integridad comercial
                    </p>
                    <dl className="mt-3 grid gap-3 text-sm text-[var(--velmix-muted)]">
                        <MetricRow label="Metodo" value={detail.payment_method} />
                        <MetricRow
                            label="Cuenta por cobrar"
                            value={detail.receivable !== null
                                ? `${detail.receivable.status} · ${formatCurrency(detail.receivable.outstanding_amount)}`
                                : 'No aplica'}
                        />
                        <MetricRow
                            label="Voucher"
                            value={detail.voucher !== null
                                ? `${detail.voucher.series}-${detail.voucher.number} · ${detail.voucher.status}`
                                : 'No emitido'}
                        />
                        <MetricRow
                            label="Credit note"
                            value={detail.credit_note !== null
                                ? `${detail.credit_note.series}-${detail.credit_note.number} · ${detail.credit_note.status}`
                                : 'Sin nota de credito'}
                        />
                        <MetricRow
                            label="Cancelacion"
                            value={detail.cancel_reason ?? 'No cancelada'}
                        />
                        <MetricRow
                            label="Credit reason"
                            value={detail.credit_reason ?? 'Sin credit note aplicada'}
                        />
                    </dl>
                </article>
            </section>

            {detail.credit_notes.length > 0 && (
                <article className="velmix-card-strong mt-5 p-4">
                    <p className="text-sm font-black uppercase tracking-[0.16em] text-[var(--velmix-muted)]">
                        Historial de credit notes
                    </p>
                    <div className="mt-3 overflow-x-auto">
                        <table className="min-w-full text-left text-sm">
                            <thead className="text-[var(--velmix-muted)]">
                                <tr>
                                    <th className="px-3 py-2 font-semibold">Documento</th>
                                    <th className="px-3 py-2 font-semibold">Estado</th>
                                    <th className="px-3 py-2 font-semibold">Total</th>
                                    <th className="px-3 py-2 font-semibold">Reembolso</th>
                                    <th className="px-3 py-2 font-semibold">Fecha</th>
                                </tr>
                            </thead>
                            <tbody>
                                {detail.credit_notes.map((creditNote) => (
                                    <tr key={creditNote.id} className="border-t border-[var(--velmix-border)]">
                                        <td className="px-3 py-3 font-semibold">
                                            {creditNote.series}-{creditNote.number}
                                        </td>
                                        <td className="px-3 py-3">
                                            <StatusBadge
                                                label={creditNote.status}
                                                tone={toneForVoucherStatus(creditNote.status)}
                                            />
                                        </td>
                                        <td className="px-3 py-3">{formatCurrency(creditNote.total_amount)}</td>
                                        <td className="px-3 py-3">{formatCurrency(creditNote.refunded_amount)}</td>
                                        <td className="px-3 py-3">{formatDateTime(creditNote.created_at)}</td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    </div>
                </article>
            )}
            </div>
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
            <p className="text-[10px] font-black uppercase tracking-[0.18em] text-[var(--velmix-muted)]">{label}</p>
            <p className="mt-2 text-2xl font-black tracking-[-0.05em]">{value}</p>
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

function toneForSaleStatus(status: string): StatusBadgeTone {
    if (status === 'completed') {
        return 'success';
    }

    if (status === 'cancelled') {
        return 'danger';
    }

    if (status === 'credited') {
        return 'warning';
    }

    return 'neutral';
}

function toneForVoucherStatus(status: string): StatusBadgeTone {
    if (status === 'accepted') {
        return 'success';
    }

    if (status === 'pending') {
        return 'warning';
    }

    if (status === 'rejected' || status === 'failed') {
        return 'danger';
    }

    return 'info';
}
