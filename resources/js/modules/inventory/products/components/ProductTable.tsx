import { StatusBadge, type StatusBadgeTone } from '@/shared/components/StatusBadge';
import { formatCurrency } from '@/shared/utils/formatters';
import type { InventoryProduct } from '@/modules/inventory/products/types';

interface ProductTableProps {
    products: InventoryProduct[];
    isFetching: boolean;
}

export function ProductTable({ products, isFetching }: ProductTableProps) {
    return (
        <div className="overflow-x-auto">
            <table className="min-w-full text-left text-sm">
                <thead className="text-[var(--velmix-muted)]">
                    <tr>
                        <th className="px-3 py-3 font-semibold">SKU</th>
                        <th className="px-3 py-3 font-semibold">Nombre</th>
                        <th className="px-3 py-3 font-semibold">Estado</th>
                        <th className="px-3 py-3 font-semibold">Controlado</th>
                        <th className="px-3 py-3 font-semibold">Last cost</th>
                        <th className="px-3 py-3 font-semibold">Average cost</th>
                    </tr>
                </thead>
                <tbody>
                    {products.length === 0 ? (
                        <tr>
                            <td className="px-3 py-6 text-[var(--velmix-muted)]" colSpan={6}>
                                {isFetching
                                    ? 'Actualizando listado de productos...'
                                    : 'No hay productos para los filtros actuales.'}
                            </td>
                        </tr>
                    ) : (
                        products.map((product) => (
                            <tr key={product.id} className="border-t border-[var(--velmix-border)]">
                                <td className="px-3 py-3 font-semibold">{product.sku}</td>
                                <td className="px-3 py-3">{product.name}</td>
                                <td className="px-3 py-3">
                                    <StatusBadge label={product.status} tone={toneForProductStatus(product.status)} />
                                </td>
                                <td className="px-3 py-3">
                                    <StatusBadge
                                        label={product.is_controlled ? 'yes' : 'no'}
                                        tone={product.is_controlled ? 'warning' : 'neutral'}
                                    />
                                </td>
                                <td className="px-3 py-3">{formatCurrency(product.last_cost)}</td>
                                <td className="px-3 py-3">{formatCurrency(product.average_cost)}</td>
                            </tr>
                        ))
                    )}
                </tbody>
            </table>
        </div>
    );
}

function toneForProductStatus(status: string): StatusBadgeTone {
    if (status === 'active' || status === 'available') {
        return 'success';
    }

    if (status === 'inactive') {
        return 'warning';
    }

    if (status === 'blocked' || status === 'immobilized') {
        return 'danger';
    }

    return 'neutral';
}
