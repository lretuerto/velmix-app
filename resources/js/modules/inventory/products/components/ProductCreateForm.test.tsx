import { fireEvent, render, screen, waitFor } from '@testing-library/react';
import { describe, expect, it, vi } from 'vitest';
import { ProductCreateForm } from '@/modules/inventory/products/components/ProductCreateForm';

describe('ProductCreateForm', () => {
    it('validates required fields before submitting', async () => {
        const onSubmit = vi.fn(async () => true);

        render(
            <ProductCreateForm
                isPending={false}
                errorMessage={null}
                successMessage={null}
                onSubmit={onSubmit}
            />,
        );

        fireEvent.click(screen.getByRole('button', { name: 'Crear producto' }));

        expect(await screen.findByText('El SKU es obligatorio.')).toBeInTheDocument();
        expect(screen.getByText('El nombre es obligatorio.')).toBeInTheDocument();
        expect(onSubmit).not.toHaveBeenCalled();
    });

    it('submits normalized product values and resets after success', async () => {
        const onSubmit = vi.fn(async () => true);

        render(
            <ProductCreateForm
                isPending={false}
                errorMessage={null}
                successMessage={null}
                onSubmit={onSubmit}
            />,
        );

        fireEvent.change(screen.getByLabelText('SKU'), { target: { value: ' PARA-500 ' } });
        fireEvent.change(screen.getByLabelText('Nombre'), { target: { value: ' Paracetamol 500mg ' } });
        fireEvent.click(screen.getByLabelText(/Marcar como producto controlado/i));
        fireEvent.click(screen.getByRole('button', { name: 'Crear producto' }));

        await waitFor(() => {
            expect(onSubmit).toHaveBeenCalledWith({
                sku: 'PARA-500',
                name: 'Paracetamol 500mg',
                is_controlled: true,
            });
        });

        await waitFor(() => {
            expect(screen.getByLabelText('SKU')).toHaveValue('');
        });
    });

    it('renders backend field validation next to the affected field', () => {
        render(
            <ProductCreateForm
                isPending={false}
                errorMessage="The given data was invalid. Campos: sku."
                fieldErrors={{
                    sku: ['El SKU ya existe para este tenant.'],
                }}
                successMessage={null}
                onSubmit={vi.fn(async () => false)}
            />,
        );

        expect(screen.getByText('El SKU ya existe para este tenant.')).toBeInTheDocument();
        expect(screen.getByText('The given data was invalid. Campos: sku.')).toBeInTheDocument();
    });
});
