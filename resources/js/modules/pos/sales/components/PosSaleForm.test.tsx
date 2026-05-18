import { fireEvent, render, screen, waitFor } from '@testing-library/react';
import { describe, expect, it, vi } from 'vitest';
import type { InventoryProduct } from '@/modules/inventory/products/types';
import { PosSaleForm } from '@/modules/pos/sales/components/PosSaleForm';
import type { SalesCustomer } from '@/modules/sales/customers/types';

const products: InventoryProduct[] = [
    {
        id: 1,
        tenant_id: 10,
        sku: 'PARA-500',
        name: 'Paracetamol 500mg',
        status: 'active',
        is_controlled: false,
        last_cost: 1,
        average_cost: 1,
    },
    {
        id: 2,
        tenant_id: 10,
        sku: 'CLON-2',
        name: 'Clonazepam 2mg',
        status: 'active',
        is_controlled: true,
        last_cost: 2,
        average_cost: 2,
    },
];

const customers: SalesCustomer[] = [
    {
        id: 15,
        document_type: 'RUC',
        document_number: '20999999001',
        name: 'Smoke Farmacia UAT',
        phone: null,
        email: null,
        credit_limit: 2500,
        credit_days: 15,
        block_on_overdue: true,
        status: 'active',
        outstanding_total: 0,
        overdue_total: 0,
        available_credit: 2500,
        credit_utilization_pct: 0,
    },
];

describe('PosSaleForm', () => {
    it('quick search fills the cart and increments an existing product without duplicating lines', async () => {
        render(
            <PosSaleForm
                products={products}
                customers={[]}
                isPending={false}
                errorMessage={null}
                onSubmit={vi.fn(async () => false)}
            />,
        );

        const searchInput = screen.getByPlaceholderText('Ej. CLON, paracetamol, SKU...');

        fireEvent.change(searchInput, { target: { value: 'clon' } });

        await waitFor(() => {
            expect(screen.queryByRole('button', { name: /PARA-500/i })).not.toBeInTheDocument();
        });

        fireEvent.click(screen.getByRole('button', { name: /CLON-2.*Clonazepam 2mg/i }));

        await waitFor(() => {
            expect(screen.getByLabelText('Producto')).toHaveValue('2');
        });

        const quantityInput = screen.getByLabelText('Cantidad') as HTMLInputElement;

        expect(quantityInput.value).toBe('1');

        fireEvent.click(screen.getByRole('button', { name: /CLON-2.*Clonazepam 2mg/i }));

        await waitFor(() => {
            expect(quantityInput.value).toBe('2');
        });

        expect(screen.queryByText('Linea 2')).not.toBeInTheDocument();
        expect(screen.getByText(/Producto controlado/i)).toBeInTheDocument();
    });

    it('quantity controls increment and clamp the current line to one unit', async () => {
        render(
            <PosSaleForm
                products={products}
                customers={[]}
                isPending={false}
                errorMessage={null}
                onSubmit={vi.fn(async () => false)}
            />,
        );

        const quantityInput = screen.getByLabelText('Cantidad') as HTMLInputElement;

        fireEvent.click(screen.getByLabelText('Sumar cantidad de linea 1'));

        await waitFor(() => {
            expect(quantityInput.value).toBe('2');
        });

        fireEvent.click(screen.getByLabelText('Restar cantidad de linea 1'));
        fireEvent.click(screen.getByLabelText('Restar cantidad de linea 1'));

        await waitFor(() => {
            expect(quantityInput.value).toBe('1');
        });
    });

    it('keeps customer selectable and auto-selects the only active customer for credit sales', async () => {
        render(
            <PosSaleForm
                products={products}
                customers={customers}
                isPending={false}
                errorMessage={null}
                onSubmit={vi.fn(async () => false)}
            />,
        );

        const paymentSelect = screen.getByLabelText('Metodo de pago');
        const customerSelect = screen.getByLabelText('Cliente') as HTMLSelectElement;

        expect(customerSelect).not.toBeDisabled();
        expect(customerSelect.value).toBe('');

        fireEvent.change(paymentSelect, { target: { value: 'credit' } });

        await waitFor(() => {
            expect(customerSelect.value).toBe('15');
        });
    });
});
