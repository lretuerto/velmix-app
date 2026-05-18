import { StatusBadge, type StatusBadgeTone } from '@/shared/components/StatusBadge';
import { cn } from '@/shared/utils/cn';
import { formatCurrency } from '@/shared/utils/formatters';
import type { PosSaleSummary } from '@/modules/pos/sales/types';

interface SaleTableProps {
    sales: PosSaleSummary[];
    isFetching: boolean;
    selectedSaleId: number | null;
    onSelectSale: (saleId: number) => void;
}

export function SaleTable({ sales, isFetching, selectedSaleId, onSelectSale }: SaleTableProps) {
    return (
        <div className="overflow-hidden rounded-[var(--velmix-radius-lg)] border border-[var(--velmix-border)] bg-white">
            <div className="overflow-x-auto">
            <table className="min-w-full text-left text-sm">
                <thead className="bg-[var(--velmix-sidebar)] text-white">
                    <tr>
                        <th className="px-4 py-3 text-[10px] font-black uppercase tracking-[0.16em]">Referencia</th>
                        <th className="px-4 py-3 text-[10px] font-black uppercase tracking-[0.16em]">Cliente</th>
                        <th className="px-4 py-3 text-[10px] font-black uppercase tracking-[0.16em]">Pago</th>
                        <th className="px-4 py-3 text-[10px] font-black uppercase tracking-[0.16em]">Estado</th>
                        <th className="px-4 py-3 text-[10px] font-black uppercase tracking-[0.16em]">Total</th>
                        <th className="px-4 py-3 text-right text-[10px] font-black uppercase tracking-[0.16em]">Acciones</th>
                    </tr>
                </thead>
                <tbody>
                    {sales.length === 0 ? (
                        <tr>
                            <td className="px-4 py-8 text-[var(--velmix-muted)]" colSpan={6}>
                                {isFetching ? 'Actualizando ventas...' : 'No hay ventas para los filtros actuales.'}
                            </td>
                        </tr>
                    ) : (
                        sales.map((sale) => {
                            const isSelected = sale.id === selectedSaleId;

                            return (
                                <tr
                                    key={sale.id}
                                    className={cn(
                                        'border-t border-[var(--velmix-border)] transition hover:bg-[var(--velmix-panel-muted)]',
                                        isSelected && 'bg-[var(--velmix-brand-soft)]/70 hover:bg-[var(--velmix-brand-soft)]/70',
                                    )}
                                >
                                    <td className="px-4 py-3">
                                        <p className="font-black tracking-[-0.02em]">{sale.reference}</p>
                                        {sale.credit_summary !== null && (
                                            <p className="text-[var(--velmix-muted)]">
                                                NC {sale.credit_summary.count}
                                            </p>
                                        )}
                                    </td>
                                    <td className="px-4 py-3">
                                        {sale.customer !== null ? (
                                            <>
                                                <p className="font-bold">{sale.customer.name}</p>
                                                <p className="text-[var(--velmix-muted)]">{sale.customer.document_number}</p>
                                            </>
                                        ) : (
                                            <p className="text-[var(--velmix-muted)]">Venta mostrador</p>
                                        )}
                                    </td>
                                    <td className="px-4 py-3">
                                        <p className="font-bold">{sale.payment_method}</p>
                                        {sale.receivable !== null && (
                                            <p className="text-[var(--velmix-muted)]">
                                                AR {formatCurrency(sale.receivable.outstanding_amount)}
                                            </p>
                                        )}
                                    </td>
                                    <td className="px-4 py-3">
                                        <div className="flex flex-wrap gap-2">
                                            <StatusBadge label={sale.status} tone={toneForSaleStatus(sale.status)} />
                                            {sale.voucher_status !== null && (
                                                <StatusBadge label={sale.voucher_status} tone={toneForVoucherStatus(sale.voucher_status)} />
                                            )}
                                        </div>
                                    </td>
                                    <td className="px-4 py-3">
                                        <p className="font-black">{formatCurrency(sale.total_amount)}</p>
                                        <p className="text-[var(--velmix-muted)]">Margen {formatCurrency(sale.gross_margin)}</p>
                                    </td>
                                    <td className="px-4 py-3 text-right">
                                        <button
                                            type="button"
                                            className="velmix-button-secondary px-3 py-2 text-xs"
                                            onClick={() => onSelectSale(sale.id)}
                                        >
                                            Ver detalle
                                        </button>
                                    </td>
                                </tr>
                            );
                        })
                    )}
                </tbody>
            </table>
            </div>
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
