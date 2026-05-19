import { afterEach, describe, expect, it, vi } from 'vitest';
import { MemoryRouter } from 'react-router-dom';
import { render, screen, waitFor } from '@testing-library/react';
import { AppProviders } from '@/core/app/providers';
import { AppRouter } from '@/core/router';
import { queryClient } from '@/core/query/client';
import type { AppBoot } from '@/core/app/boot';

vi.mock('@/modules/inventory/products/api/products', () => ({
    fetchProducts: vi.fn(async () => []),
}));

vi.mock('@/modules/sales/customers/api/customers', () => ({
    fetchCustomers: vi.fn(async () => []),
    fetchCustomerStatement: vi.fn(),
    createCustomer: vi.fn(),
    updateCustomer: vi.fn(),
}));

vi.mock('@/modules/pos/sales/api/sales', () => ({
    fetchPosSales: vi.fn(async () => []),
    fetchPosSaleDetail: vi.fn(),
    createPosSale: vi.fn(),
}));

vi.mock('@/modules/pricing/quotes/api/quotes', () => ({
    createPricingQuote: vi.fn(),
    fetchPricingQuote: vi.fn(),
    checkoutPricingQuote: vi.fn(),
}));

describe('AppRouter', () => {
    afterEach(() => {
        queryClient.clear();
        delete window.__VELMIX_BOOT__;
    });

    it('lazy-loads the POS route for an authorized tenant session', async () => {
        window.__VELMIX_BOOT__ = makeBoot([
            'pos.sale.read',
            'pos.sale.execute',
            'pricing.quote.create',
        ]);

        renderRoute('/pos/sales');

        expect(screen.getByText('Cargando modulo')).toBeInTheDocument();

        await waitFor(() => {
            expect(screen.getByRole('heading', { name: 'Ventas POS' })).toBeInTheDocument();
        });

        expect(screen.getByRole('heading', { name: 'Cotizar y vender' })).toBeInTheDocument();
        expect(screen.getByText('Sin cotizacion activa')).toBeInTheDocument();
    });

    it('keeps restricted routes closed before lazy module execution when there is no session', async () => {
        window.__VELMIX_BOOT__ = makeBoot(['pos.sale.read'], false);

        renderRoute('/pos/sales');

        await waitFor(() => {
            expect(screen.getByText('Sesion requerida')).toBeInTheDocument();
        });

        const loginLinks = screen.getAllByRole('link', { name: 'Iniciar sesion' });

        expect(loginLinks[loginLinks.length - 1]).toHaveAttribute(
            'href',
            '/login?redirect=%2Fpos%2Fsales',
        );
        expect(screen.queryByRole('heading', { name: 'Ventas POS' })).not.toBeInTheDocument();
    });

    it('renders the not found state for unknown frontend routes', () => {
        window.__VELMIX_BOOT__ = makeBoot([]);

        renderRoute('/ruta/no-existe');

        expect(screen.getByText('Ruta no encontrada')).toBeInTheDocument();
        expect(screen.getByRole('link', { name: 'Volver al workspace' })).toHaveAttribute('href', '/');
    });
});

function renderRoute(initialEntry: string) {
    render(
        <MemoryRouter initialEntries={[initialEntry]}>
            <AppProviders>
                <AppRouter />
            </AppProviders>
        </MemoryRouter>,
    );
}

function makeBoot(permissions: string[], authenticated = true): AppBoot {
    return {
        app: {
            name: 'VELMiX ERP',
            environment: 'testing',
            request_id: 'test-request',
            frontend_stage: 'test',
        },
        auth: {
            authenticated,
            mode: authenticated ? 'session' : 'guest',
            user: authenticated
                ? {
                    id: 1,
                    name: 'QA Operator',
                    email: 'qa@example.test',
                }
                : null,
        },
        tenant: {
            selected: authenticated
                ? {
                    id: 10,
                    code: 'demo',
                    name: 'Demo tenant',
                    status: 'active',
                }
                : null,
            memberships: authenticated
                ? [
                    {
                        id: 10,
                        code: 'demo',
                        name: 'Demo tenant',
                        status: 'active',
                    },
                ]
                : [],
            selection_error: null,
        },
        rbac: {
            roles: [],
            permissions,
        },
        links: {
            health_live: '/health/live',
            health_ready: '/health/ready',
            auth_me: '/auth/me',
            tenant_ping: '/tenant/ping',
        },
    };
}
