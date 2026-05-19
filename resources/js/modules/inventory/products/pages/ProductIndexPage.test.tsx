import { afterEach, describe, expect, it, vi } from 'vitest';
import { fireEvent, render, screen, waitFor } from '@testing-library/react';
import { AppProviders } from '@/core/app/providers';
import type { AppBoot } from '@/core/app/boot';
import { ApiError } from '@/core/api/errors';
import { queryClient } from '@/core/query/client';
import { ProductIndexPage } from '@/modules/inventory/products/pages/ProductIndexPage';
import { createProduct, fetchProducts } from '@/modules/inventory/products/api/products';
import type { InventoryProduct } from '@/modules/inventory/products/types';

vi.mock('@/modules/inventory/products/api/products', () => ({
    fetchProducts: vi.fn(),
    createProduct: vi.fn(),
}));

const fetchProductsMock = vi.mocked(fetchProducts);
const createProductMock = vi.mocked(createProduct);

describe('ProductIndexPage', () => {
    afterEach(() => {
        queryClient.clear();
        vi.clearAllMocks();
        delete window.__VELMIX_BOOT__;
    });

    it('loads products and filters the catalog without server round trips', async () => {
        fetchProductsMock.mockResolvedValue(products);
        window.__VELMIX_BOOT__ = makeBoot(['inventory.product.create']);

        renderPage();

        expect(screen.getByText('Cargando catalogo')).toBeInTheDocument();
        expect(await screen.findByText('PARA-500')).toBeInTheDocument();
        expect(screen.getByText('CLON-2')).toBeInTheDocument();

        fireEvent.change(screen.getByPlaceholderText('SKU, nombre o estado'), { target: { value: 'clon' } });

        await waitFor(() => {
            expect(screen.queryByText('PARA-500')).not.toBeInTheDocument();
        });
        expect(screen.getByText('CLON-2')).toBeInTheDocument();
        expect(fetchProductsMock).toHaveBeenCalledTimes(1);
        expect(createProductMock).not.toHaveBeenCalled();
    });

    it('keeps administrative creation closed without create permission', async () => {
        fetchProductsMock.mockResolvedValue(products);
        window.__VELMIX_BOOT__ = makeBoot([]);

        renderPage();

        expect(await screen.findByText('PARA-500')).toBeInTheDocument();
        expect(screen.getByText('Acceso de solo lectura')).toBeInTheDocument();
        expect(screen.queryByRole('button', { name: 'Crear producto' })).not.toBeInTheDocument();
    });

    it('renders an operational API error with retry context', async () => {
        fetchProductsMock
            .mockRejectedValueOnce(new ApiError('Catalog snapshot stale.', 409, 'req-products-001'))
            .mockResolvedValueOnce([]);
        window.__VELMIX_BOOT__ = makeBoot(['inventory.product.create']);

        renderPage();

        expect(await screen.findByText('No pudimos cargar el catalogo')).toBeInTheDocument();
        expect(screen.getByText(/Request ID: req-products-001/i)).toBeInTheDocument();

        const retryButtons = screen.getAllByRole('button', { name: 'Refrescar catalogo' });

        expect(retryButtons).toHaveLength(2);
        fireEvent.click(retryButtons[1]!);

        await waitFor(() => {
            expect(fetchProductsMock).toHaveBeenCalledTimes(2);
        });
    });
});

function renderPage() {
    render(
        <AppProviders>
            <ProductIndexPage />
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
            permissions: ['inventory.product.read', ...permissions],
        },
        links: {
            health_live: '/health/live',
            health_ready: '/health/ready',
            auth_me: '/auth/me',
            tenant_ping: '/tenant/ping',
        },
    };
}

const products: InventoryProduct[] = [
    {
        id: 1,
        tenant_id: 10,
        sku: 'PARA-500',
        name: 'Paracetamol 500mg',
        status: 'active',
        is_controlled: false,
        last_cost: 1,
        average_cost: 1.2,
    },
    {
        id: 2,
        tenant_id: 10,
        sku: 'CLON-2',
        name: 'Clonazepam 2mg',
        status: 'active',
        is_controlled: true,
        last_cost: 2,
        average_cost: 2.5,
    },
];
