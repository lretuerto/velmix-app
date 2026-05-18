import { fireEvent, render, screen, waitFor } from '@testing-library/react';
import { describe, expect, it, vi } from 'vitest';
import { CustomerForm } from '@/modules/sales/customers/components/CustomerForm';
import type { CustomerFormData } from '@/modules/sales/customers/schema';

describe('CustomerForm', () => {
    it('validates the customer payload before submitting', async () => {
        const onSubmit = vi.fn<(values: CustomerFormData) => Promise<void>>(async () => undefined);

        render(
            <CustomerForm
                mode="create"
                isPending={false}
                errorMessage={null}
                successMessage={null}
                onSubmit={onSubmit}
            />,
        );

        fireEvent.click(screen.getByRole('button', { name: 'Crear cliente' }));

        expect(await screen.findByText('El numero de documento es obligatorio.')).toBeInTheDocument();
        expect(screen.getByText('El nombre del cliente es obligatorio.')).toBeInTheDocument();
        expect(onSubmit).not.toHaveBeenCalled();
    });

    it('submits normalized create values', async () => {
        const onSubmit = vi.fn<(values: CustomerFormData) => Promise<void>>(async () => undefined);

        render(
            <CustomerForm
                mode="create"
                isPending={false}
                errorMessage={null}
                successMessage={null}
                onSubmit={onSubmit}
            />,
        );

        fireEvent.change(screen.getByLabelText('Numero de documento'), { target: { value: ' 12345678 ' } });
        fireEvent.change(screen.getByLabelText('Nombre del cliente'), { target: { value: ' Cliente Mostrador ' } });
        fireEvent.change(screen.getByLabelText('Correo'), { target: { value: 'cliente@velmix.test' } });
        fireEvent.change(screen.getByLabelText('Limite de credito'), { target: { value: '500' } });
        fireEvent.change(screen.getByLabelText('Dias de credito'), { target: { value: '15' } });
        fireEvent.click(screen.getByRole('button', { name: 'Crear cliente' }));

        await waitFor(() => {
            expect(onSubmit).toHaveBeenCalledTimes(1);
            expect(onSubmit.mock.calls[0]?.[0]).toEqual({
                document_type: 'dni',
                document_number: '12345678',
                name: 'Cliente Mostrador',
                phone: '',
                email: 'cliente@velmix.test',
                credit_limit: '500',
                credit_days: '15',
                block_on_overdue: true,
                status: 'active',
            });
        });
    });

    it('renders backend field validation and supports cancelling update mode', () => {
        const onCancelEdit = vi.fn();

        render(
            <CustomerForm
                mode="update"
                initialValues={customerValues}
                isPending={false}
                errorMessage="The given data was invalid. Campos: document_number, email."
                fieldErrors={{
                    document_number: ['El documento ya existe para este tenant.'],
                    email: ['El correo no tiene un dominio permitido.'],
                }}
                successMessage={null}
                onSubmit={vi.fn(async () => undefined)}
                onCancelEdit={onCancelEdit}
            />,
        );

        expect(screen.getByLabelText('Estado')).toHaveValue('inactive');
        expect(screen.getByText('El documento ya existe para este tenant.')).toBeInTheDocument();
        expect(screen.getByText('El correo no tiene un dominio permitido.')).toBeInTheDocument();

        fireEvent.click(screen.getByRole('button', { name: 'Cancelar edicion' }));

        expect(onCancelEdit).toHaveBeenCalledTimes(1);
    });
});

const customerValues: CustomerFormData = {
    document_type: 'ruc',
    document_number: '20123456789',
    name: 'Farmacia Norte',
    phone: '999111222',
    email: 'compras@farmacianorte.test',
    credit_limit: '1000',
    credit_days: '30',
    block_on_overdue: true,
    status: 'inactive',
};
