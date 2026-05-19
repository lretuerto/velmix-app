import { StatusBadge, type StatusBadgeTone } from '@/shared/components/StatusBadge';
import { cn } from '@/shared/utils/cn';
import { formatCurrency, formatDate } from '@/shared/utils/formatters';
import type { SaleReceivableSummary } from '@/modules/sales/receivables/types';

interface ReceivableTableProps {
    receivables: SaleReceivableSummary[];
    isFetching: boolean;
    selectedReceivableId: number | null;
    onSelectReceivable: (receivableId: number) => void;
}

export function ReceivableTable({
    receivables,
    isFetching,
    selectedReceivableId,
    onSelectReceivable,
}: ReceivableTableProps) {
    return (
        <div className="overflow-x-auto">
            <table className="min-w-full text-left text-sm">
                <thead className="text-[var(--velmix-muted)]">
                    <tr>
                        <th className="px-3 py-3 font-semibold">Venta</th>
                        <th className="px-3 py-3 font-semibold">Cliente</th>
                        <th className="px-3 py-3 font-semibold">Vencimiento</th>
                        <th className="px-3 py-3 font-semibold">Estado</th>
                        <th className="px-3 py-3 font-semibold">Saldo</th>
                        <th className="px-3 py-3 font-semibold text-right">Acciones</th>
                    </tr>
                </thead>
                <tbody>
                    {receivables.length === 0 ? (
                        <tr>
                            <td className="px-3 py-6 text-[var(--velmix-muted)]" colSpan={6}>
                                {isFetching
                                    ? 'Actualizando cuentas por cobrar...'
                                    : 'No hay cuentas por cobrar para los filtros actuales.'}
                            </td>
                        </tr>
                    ) : (
                        receivables.map((receivable) => {
                            const isSelected = receivable.id === selectedReceivableId;

                            return (
                                <tr
                                    key={receivable.id}
                                    className={cn(
                                        'border-t border-[var(--velmix-border)] transition',
                                        isSelected && 'bg-[var(--velmix-brand-soft)]/60',
                                    )}
                                >
                                    <td className="px-3 py-3">
                                        <p className="font-semibold">{receivable.sale_reference}</p>
                                        <p className="text-[var(--velmix-muted)]">#{receivable.id}</p>
                                    </td>
                                    <td className="px-3 py-3">
                                        <p className="font-semibold">{receivable.customer.name}</p>
                                        <p className="text-[var(--velmix-muted)]">
                                            {receivable.customer.document_type.toUpperCase()} {receivable.customer.document_number}
                                        </p>
                                    </td>
                                    <td className="px-3 py-3">
                                        <p>{formatDate(receivable.due_at)}</p>
                                        <p className="text-[var(--velmix-muted)]">{labelForAgingBucket(receivable.aging_bucket)}</p>
                                    </td>
                                    <td className="px-3 py-3">
                                        <div className="flex flex-wrap gap-2">
                                            <StatusBadge label={receivable.status} tone={toneForReceivableStatus(receivable.status)} />
                                            <StatusBadge
                                                label={receivable.effective_status}
                                                tone={toneForEffectiveStatus(receivable.effective_status)}
                                            />
                                        </div>
                                    </td>
                                    <td className="px-3 py-3">
                                        <p className="font-semibold">{formatCurrency(receivable.outstanding_amount)}</p>
                                        <p className="text-[var(--velmix-muted)]">
                                            Pagado {formatCurrency(receivable.paid_amount)}
                                        </p>
                                    </td>
                                    <td className="px-3 py-3 text-right">
                                        <button
                                            type="button"
                                            className="rounded-[var(--velmix-radius-md)] border border-[var(--velmix-border-strong)] px-3 py-2 text-xs font-semibold transition hover:border-[var(--velmix-brand)]"
                                            onClick={() => onSelectReceivable(receivable.id)}
                                        >
                                            Abrir detalle
                                        </button>
                                    </td>
                                </tr>
                            );
                        })
                    )}
                </tbody>
            </table>
        </div>
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

function labelForAgingBucket(bucket: string): string {
    switch (bucket) {
        case 'overdue_1_30':
            return 'Mora 1-30 dias';
        case 'overdue_31_60':
            return 'Mora 31-60 dias';
        case 'overdue_61_plus':
            return 'Mora 61+ dias';
        case 'paid':
            return 'Pagada';
        default:
            return 'Al dia';
    }
}
