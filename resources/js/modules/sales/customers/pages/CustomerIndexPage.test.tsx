import { afterEach, describe, expect, it, vi } from 'vitest';
import { fireEvent, render, screen, waitFor } from '@testing-library/react';
import { AppProviders } from '@/core/app/providers';
import type { AppBoot } from '@/core/app/boot';
import { ApiError } from '@/core/api/errors';
import { queryClient } from '@/core/query/client';
import { CustomerIndexPage } from '@/modules/sales/customers/pages/CustomerIndexPage';
import {
    createCustomer,
    fetchCustomers,
    fetchCustomerStatement,
    updateCustomer,
} from '@/modules/sales/customers/api/customers';
import type { SalesCustomer } from '@/modules/sales/customers/types';

vi.mock('@/modules/sales/customers/api/customers', () => ({
    fetchCustomers: vi.fn(),
    fetchCustomerStatement: vi.fn(),
    createCustomer: vi.fn(),
    updateCustomer: vi.fn(),
}));

const fetchCustomersMock = vi.mocked(fetchCustomers);
const fetchCustomerStatementMock = vi.mocked(fetchCustomerStatement);
const createCustomerMock = vi.mocked(createCustomer);
const updateCustomerMock = vi.mocked(updateCustomer);

describe('CustomerIndexPage', () => {
    afterEach(() => {
        queryClient.clear();
        vi.clearAllMocks();
        delete window.__VELMIX_BOOT__;
    });

    it('loads customers and filters the commercial master locally', async () => {
        fetchCustomersMock.mockResolvedValue(customers);
        window.__VELMIX_BOOT__ = makeBoot(['sales.customer.create', 'sales.customer.update']);

        renderPage();

        expect(screen.getByText('Cargando maestro de clientes')).toBeInTheDocument();
        expect(await screen.findByText('Farmacia Norte')).toBeInTheDocument();
        expect(screen.getByText('Botica Sur')).toBeInTheDocument();

        fireEvent.change(screen.getByPlaceholderText('Documento, nombre, correo o estado'), { target: { value: 'botica' } });

        await waitFor(() => {
            expect(screen.queryByText('Farmacia Norte')).not.toBeInTheDocument();
        });
        expect(screen.getByText('Botica Sur')).toBeInTheDocument();
        expect(fetchCustomersMock).toHaveBeenCalledTimes(1);
        expect(fetchCustomerStatementMock).not.toHaveBeenCalled();
    });

    it('keeps customer mutation actions closed for read-only operators', async () => {
        fetchCustomersMock.mockResolvedValue(customers);
        window.__VELMIX_BOOT__ = makeBoot([]);

        renderPage();

        expect(await screen.findByText('Farmacia Norte')).toBeInTheDocument();
        expect(screen.getByText('Acceso de solo lectura')).toBeInTheDocument();
        expect(screen.queryByRole('button', { name: 'Nuevo cliente' })).not.toBeInTheDocument();
        expect(screen.queryByRole('button', { name: 'Editar' })).not.toBeInTheDocument();
        expect(createCustomerMock).not.toHaveBeenCalled();
        expect(updateCustomerMock).not.toHaveBeenCalled();
    });

    it('renders an operational API error with retry context', async () => {
        fetchCustomersMock
            .mockRejectedValueOnce(new ApiError('Customer snapshot stale.', 409, 'req-customers-001'))
            .mockResolvedValueOnce([]);
        window.__VELMIX_BOOT__ = makeBoot(['sales.customer.create']);

        renderPage();

        expect(await screen.findByText('No pudimos cargar el maestro de clientes')).toBeInTheDocument();
        expect(screen.getByText(/Request ID: req-customers-001/i)).toBeInTheDocument();

        const retryButtons = screen.getAllByRole('button', { name: 'Refrescar modulo' });

        expect(retryButtons).toHaveLength(2);
        fireEvent.click(retryButtons[1]!);

        await waitFor(() => {
            expect(fetchCustomersMock).toHaveBeenCalledTimes(2);
        });
    });
});

function renderPage() {
    render(
        <AppProviders>
            <CustomerIndexPage />
        </AppProviders>,
    );
}

function makeBoot(permissions: string[]): AppBoot {
    return {
        app: {
            name: 'VELMiX ERP',
            environment: 'testing',
            request_id: 'test-request',
            frontend_stage: 'test',
        },
        auth: {
            authenticated: true,
            mode: 'session',
            user: {
                id: 1,
                name: 'QA Operator',
                email: 'qa@example.test',
            },
        },
        tenant: {
            selected: {
                id: 10,
                code: 'demo',
                name: 'Demo tenant',
                status: 'active',
            },
            memberships: [
                {
                    id: 10,
                    code: 'demo',
                    name: 'Demo tenant',
                    status: 'active',
                },
            ],
            selection_error: null,
        },
        rbac: {
            roles: [],
            permissions: ['sales.customer.read', ...permissions],
        },
        links: {
            health_live: '/health/live',
            health_ready: '/health/ready',
            auth_me: '/auth/me',
            tenant_ping: '/tenant/ping',
        },
    };
}

const customers: SalesCustomer[] = [
    {
        id: 1,
        document_type: 'ruc',
        document_number: '20111111111',
        name: 'Farmacia Norte',
        phone: '999111222',
        email: 'norte@velmix.test',
        credit_limit: 1000,
        credit_days: 30,
        block_on_overdue: true,
        status: 'active',
        outstanding_total: 250,
        overdue_total: 0,
        available_credit: 750,
        credit_utilization_pct: 25,
    },
    {
        id: 2,
        document_type: 'ruc',
        document_number: '20222222222',
        name: 'Botica Sur',
        phone: null,
        email: 'sur@velmix.test',
        credit_limit: null,
        credit_days: null,
        block_on_overdue: true,
        status: 'inactive',
        outstanding_total: 0,
        overdue_total: 0,
        available_credit: null,
        credit_utilization_pct: null,
    },
];
