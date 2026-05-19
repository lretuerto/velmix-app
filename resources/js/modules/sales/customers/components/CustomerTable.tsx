import { StatusBadge, type StatusBadgeTone } from '@/shared/components/StatusBadge';
import { cn } from '@/shared/utils/cn';
import { formatCurrency } from '@/shared/utils/formatters';
import type { SalesCustomer } from '@/modules/sales/customers/types';

interface CustomerTableProps {
    customers: SalesCustomer[];
    isFetching: boolean;
    selectedCustomerId: number | null;
    canUpdateCustomer: boolean;
    onSelectCustomer: (customerId: number) => void;
    onEditCustomer: (customerId: number) => void;
}

export function CustomerTable({
    customers,
    isFetching,
    selectedCustomerId,
    canUpdateCustomer,
    onSelectCustomer,
    onEditCustomer,
}: CustomerTableProps) {
    return (
        <div className="overflow-x-auto">
            <table className="min-w-full text-left text-sm">
                <thead className="text-[var(--velmix-muted)]">
                    <tr>
                        <th className="px-3 py-3 font-semibold">Documento</th>
                        <th className="px-3 py-3 font-semibold">Cliente</th>
                        <th className="px-3 py-3 font-semibold">Credito</th>
                        <th className="px-3 py-3 font-semibold">Cartera</th>
                        <th className="px-3 py-3 font-semibold">Estado</th>
                        <th className="px-3 py-3 font-semibold text-right">Acciones</th>
                    </tr>
                </thead>
                <tbody>
                    {customers.length === 0 ? (
                        <tr>
                            <td className="px-3 py-6 text-[var(--velmix-muted)]" colSpan={6}>
                                {isFetching
                                    ? 'Actualizando listado de clientes...'
                                    : 'No hay clientes para los filtros actuales.'}
                            </td>
                        </tr>
                    ) : (
                        customers.map((customer) => {
                            const isSelected = customer.id === selectedCustomerId;

                            return (
                                <tr
                                    key={customer.id}
                                    className={cn(
                                        'border-t border-[var(--velmix-border)] transition',
                                        isSelected && 'bg-[var(--velmix-brand-soft)]/60',
                                    )}
                                >
                                    <td className="px-3 py-3">
                                        <p className="font-semibold uppercase">{customer.document_type}</p>
                                        <p className="text-[var(--velmix-muted)]">{customer.document_number}</p>
                                    </td>
                                    <td className="px-3 py-3">
                                        <p className="font-semibold">{customer.name}</p>
                                        <p className="text-[var(--velmix-muted)]">
                                            {customer.email ?? customer.phone ?? 'Sin contacto registrado'}
                                        </p>
                                    </td>
                                    <td className="px-3 py-3">
                                        <p className="font-semibold">
                                            {customer.credit_limit !== null ? formatCurrency(customer.credit_limit) : 'Sin limite'}
                                        </p>
                                        <p className="text-[var(--velmix-muted)]">
                                            Disponible{' '}
                                            {customer.available_credit !== null ? formatCurrency(customer.available_credit) : 'N/A'}
                                        </p>
                                    </td>
                                    <td className="px-3 py-3">
                                        <p className="font-semibold">{formatCurrency(customer.outstanding_total)}</p>
                                        <p className="text-[var(--velmix-muted)]">
                                            Vencido {formatCurrency(customer.overdue_total)}
                                        </p>
                                    </td>
                                    <td className="px-3 py-3">
                                        <div className="flex flex-wrap gap-2">
                                            <StatusBadge
                                                label={customer.status}
                                                tone={toneForCustomerStatus(customer.status)}
                                            />
                                            {customer.overdue_total > 0 && (
                                                <StatusBadge label="overdue" tone="danger" />
                                            )}
                                        </div>
                                    </td>
                                    <td className="px-3 py-3">
                                        <div className="flex justify-end gap-2">
                                            <button
                                                type="button"
                                                className="rounded-[var(--velmix-radius-md)] border border-[var(--velmix-border-strong)] px-3 py-2 text-xs font-semibold transition hover:border-[var(--velmix-brand)]"
                                                onClick={() => onSelectCustomer(customer.id)}
                                            >
                                                Cuenta
                                            </button>
                                            {canUpdateCustomer && (
                                                <button
                                                    type="button"
                                                    className="rounded-[var(--velmix-radius-md)] bg-[var(--velmix-brand)] px-3 py-2 text-xs font-semibold text-white transition hover:opacity-90"
                                                    onClick={() => onEditCustomer(customer.id)}
                                                >
                                                    Editar
                                                </button>
                                            )}
                                        </div>
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

function toneForCustomerStatus(status: string): StatusBadgeTone {
    if (status === 'active') {
        return 'success';
    }

    if (status === 'inactive') {
        return 'warning';
    }

    return 'neutral';
}
