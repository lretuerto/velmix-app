import { afterEach, describe, expect, it, vi } from 'vitest';
import { fireEvent, render, screen, waitFor } from '@testing-library/react';
import { AppProviders } from '@/core/app/providers';
import type { AppBoot } from '@/core/app/boot';
import { ApiError } from '@/core/api/errors';
import { queryClient } from '@/core/query/client';
import { fetchProducts } from '@/modules/inventory/products/api/products';
import type { InventoryProduct } from '@/modules/inventory/products/types';
import { createPosSale, fetchPosSaleDetail, fetchPosSales } from '@/modules/pos/sales/api/sales';
import { PosSaleIndexPage } from '@/modules/pos/sales/pages/PosSaleIndexPage';
import type { PosSaleCreated, PosSaleSummary } from '@/modules/pos/sales/types';
import { checkoutPricingQuote, createPricingQuote } from '@/modules/pricing/quotes/api/quotes';
import type { PricingQuote, PricingQuoteCheckoutResult } from '@/modules/pricing/quotes/types';
import { fetchCustomers } from '@/modules/sales/customers/api/customers';
import type { SalesCustomer } from '@/modules/sales/customers/types';

vi.mock('@/modules/inventory/products/api/products', () => ({
    fetchProducts: vi.fn(),
    createProduct: vi.fn(),
}));

vi.mock('@/modules/sales/customers/api/customers', () => ({
    fetchCustomers: vi.fn(),
    fetchCustomerStatement: vi.fn(),
    createCustomer: vi.fn(),
    updateCustomer: vi.fn(),
}));

vi.mock('@/modules/pos/sales/api/sales', () => ({
    fetchPosSales: vi.fn(),
    fetchPosSaleDetail: vi.fn(),
    createPosSale: vi.fn(),
}));

vi.mock('@/modules/pricing/quotes/api/quotes', () => ({
    createPricingQuote: vi.fn(),
    fetchPricingQuote: vi.fn(),
    checkoutPricingQuote: vi.fn(),
}));

const fetchProductsMock = vi.mocked(fetchProducts);
const fetchCustomersMock = vi.mocked(fetchCustomers);
const fetchPosSalesMock = vi.mocked(fetchPosSales);
const fetchPosSaleDetailMock = vi.mocked(fetchPosSaleDetail);
const createPosSaleMock = vi.mocked(createPosSale);
const createPricingQuoteMock = vi.mocked(createPricingQuote);
const checkoutPricingQuoteMock = vi.mocked(checkoutPricingQuote);

describe('PosSaleIndexPage', () => {
    afterEach(() => {
        queryClient.clear();
        vi.clearAllMocks();
        delete window.__VELMIX_BOOT__;
    });

    it('generates a server-side quote before idempotent checkout without using legacy sale pricing', async () => {
        fetchProductsMock.mockResolvedValue(products);
        fetchCustomersMock.mockResolvedValue(customers);
        fetchPosSalesMock.mockResolvedValue([saleSummary]);
        fetchPosSalesMock.mockResolvedValueOnce([]);
        fetchPosSaleDetailMock.mockResolvedValue(saleDetail);
        createPricingQuoteMock.mockResolvedValue(quote);
        checkoutPricingQuoteMock.mockResolvedValue(checkoutResult);
        window.__VELMIX_BOOT__ = makeBoot(['pos.sale.execute', 'pricing.quote.create']);

        renderPage();

        fireEvent.click(await screen.findByRole('button', { name: /PARA-500.*Paracetamol 500mg/i }));
        fireEvent.click(screen.getByRole('button', { name: 'Cotizar venta POS' }));

        await waitFor(() => {
            expect(createPricingQuoteMock).toHaveBeenCalledWith(
                {
                    payment_method: 'cash',
                    customer_id: null,
                    due_at: null,
                    channel: 'retail',
                    items: [
                        {
                            product_id: 1,
                            quantity: 1,
                        },
                    ],
                },
                {
                    idempotencyKey: expect.stringMatching(/^pos-pricing-quote-create-/),
                },
            );
        });

        expect(await screen.findByText(/Quote #501/i)).toBeInTheDocument();
        expect(screen.getByText('PROMO10 · Descuento laboratorio')).toBeInTheDocument();

        fireEvent.click(screen.getByRole('button', { name: 'Confirmar venta con quote' }));

        await waitFor(() => {
            expect(checkoutPricingQuoteMock).toHaveBeenCalledWith(
                501,
                {
                    quote_hash: 'quote-hash-501',
                    due_at: null,
                    line_inputs: [],
                },
                {
                    idempotencyKey: expect.stringMatching(/^pos-pricing-quote-501-checkout-/),
                },
            );
        });

        expect(createPosSaleMock).not.toHaveBeenCalled();
        expect(await screen.findByText('Venta registrada')).toBeInTheDocument();

        await waitFor(() => {
            expect(fetchPosSalesMock).toHaveBeenCalledTimes(2);
        });
    });

    it('keeps checkout closed when the operator can sell but cannot create pricing quotes', async () => {
        fetchProductsMock.mockResolvedValue(products);
        fetchCustomersMock.mockResolvedValue(customers);
        fetchPosSalesMock.mockResolvedValue([]);
        window.__VELMIX_BOOT__ = makeBoot(['pos.sale.execute']);

        renderPage();

        expect(await screen.findByText('Falta permiso de pricing')).toBeInTheDocument();
        expect(screen.queryByRole('button', { name: 'Cotizar venta POS' })).not.toBeInTheDocument();
        expect(createPricingQuoteMock).not.toHaveBeenCalled();
    });

    it('surfaces checkout failure with request trace and keeps the quote available for retry', async () => {
        fetchProductsMock.mockResolvedValue(products);
        fetchCustomersMock.mockResolvedValue(customers);
        fetchPosSalesMock.mockResolvedValue([]);
        createPricingQuoteMock.mockResolvedValue(quote);
        checkoutPricingQuoteMock.mockRejectedValue(new ApiError('Quote already consumed.', 409, 'req-pos-checkout-001'));
        window.__VELMIX_BOOT__ = makeBoot(['pos.sale.execute', 'pricing.quote.create']);

        renderPage();

        fireEvent.click(await screen.findByRole('button', { name: /PARA-500.*Paracetamol 500mg/i }));
        fireEvent.click(screen.getByRole('button', { name: 'Cotizar venta POS' }));

        expect(await screen.findByText(/Quote #501/i)).toBeInTheDocument();

        fireEvent.click(screen.getByRole('button', { name: 'Confirmar venta con quote' }));

        expect(await screen.findByText('No pudimos confirmar la venta')).toBeInTheDocument();
        expect(screen.getByText(/Request ID: req-pos-checkout-001/i)).toBeInTheDocument();
        expect(screen.getByRole('button', { name: 'Confirmar venta con quote' })).toBeEnabled();
        expect(screen.getByText(/Quote #501/i)).toBeInTheDocument();
    });
});

function renderPage() {
    render(
        <AppProviders>
            <PosSaleIndexPage />
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
            permissions: ['pos.sale.read', ...permissions],
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
        average_cost: 2.4,
    },
];

const customers: SalesCustomer[] = [
    {
        id: 20,
        document_type: 'RUC',
        document_number: '20123456789',
        name: 'Farmacia Central',
        phone: null,
        email: null,
        credit_limit: 1000,
        credit_days: 15,
        block_on_overdue: true,
        status: 'active',
        outstanding_total: 0,
        overdue_total: 0,
        available_credit: 1000,
        credit_utilization_pct: 0,
    },
];

const quote: PricingQuote = {
    id: 501,
    status: 'quoted',
    quote_hash: 'quote-hash-501',
    channel: 'retail',
    payment_method: 'cash',
    expires_at: '2099-01-01T00:00:00Z',
    currency: 'PEN',
    customer: null,
    price_list: {
        id: 31,
        code: 'RETAIL',
        name: 'Retail',
        status: 'active',
        channel: 'retail',
        currency: 'PEN',
        priority: 10,
        is_default: true,
    },
    summary: {
        subtotal_amount: 12,
        discount_amount: 2,
        total_amount: 10,
    },
    items: [
        {
            id: 7001,
            product_id: 1,
            product_sku: 'PARA-500',
            product_name: 'Paracetamol 500mg',
            requested_quantity: 1,
            resolved_price_list_item_id: 100,
            base_unit_price: 12,
            final_unit_price: 10,
            line_discount_amount: 2,
            line_total: 10,
            commercial_context: {
                product: {
                    id: 1,
                    sku: 'PARA-500',
                    name: 'Paracetamol 500mg',
                    status: 'active',
                    commercial_status: 'active',
                    laboratory_supplier_id: 44,
                },
                price_source: 'price_list',
                price_list: {
                    id: 31,
                    code: 'RETAIL',
                    channel: 'retail',
                    currency: 'PEN',
                },
            },
            adjustments: [
                {
                    id: 9001,
                    type: 'promotion_discount',
                    description: 'Descuento laboratorio',
                    promotion_id: 801,
                    promotion_rule_id: 802,
                    promotion_code: 'PROMO10',
                    promotion_name: 'Descuento laboratorio',
                    sponsor_supplier: {
                        id: 44,
                        name: 'Laboratorio Norte',
                    },
                    quantity: 1,
                    unit_delta: -2,
                    total_delta: -2,
                    metadata: {},
                },
            ],
        },
    ],
    warnings: [],
    applied_promotions: [
        {
            id: 801,
            code: 'PROMO10',
            name: 'Descuento laboratorio',
            discount_amount: 2,
            sponsor_supplier: {
                id: 44,
                name: 'Laboratorio Norte',
            },
        },
    ],
};

const saleCreated: PosSaleCreated = {
    sale_id: 9001,
    reference: 'POS-9001',
    payment_method: 'cash',
    customer: null,
    receivable: null,
    total_amount: 10,
    gross_cost: 4,
    gross_margin: 6,
    items: [
        {
            product_id: 1,
            product_sku: 'PARA-500',
            quantity: 1,
            unit_price: 10,
            unit_cost_snapshot: 4,
            line_total: 10,
            cost_amount: 4,
            gross_margin: 6,
            prescription_code: null,
            approval_code: null,
            allocations: [
                {
                    lot_id: 501,
                    lot_code: 'LOT-A',
                    quantity: 1,
                    remaining_stock: 19,
                },
            ],
        },
    ],
};

const checkoutResult: PricingQuoteCheckoutResult = {
    quote: {
        id: 501,
        status: 'consumed',
        quote_hash: 'quote-hash-501',
        sale_id: 9001,
        summary: {
            subtotal_amount: 12,
            discount_amount: 2,
            total_amount: 10,
        },
        currency: 'PEN',
    },
    sale: saleCreated,
};

const saleSummary: PosSaleSummary = {
    id: 9001,
    reference: 'POS-9001',
    status: 'completed',
    payment_method: 'cash',
    total_amount: 10,
    gross_cost: 4,
    gross_margin: 6,
    cancel_reason: null,
    cancelled_at: null,
    credit_reason: null,
    credited_at: null,
    customer: null,
    receivable: null,
    voucher_id: null,
    voucher_status: null,
    credit_note: null,
    credit_summary: null,
};

const saleDetail = {
    id: 9001,
    reference: 'POS-9001',
    status: 'completed',
    payment_method: 'cash',
    total_amount: 10,
    gross_cost: 4,
    gross_margin: 6,
    cancel_reason: null,
    cancelled_at: null,
    credit_reason: null,
    credited_at: null,
    customer: null,
    receivable: null,
    voucher: null,
    credit_note: null,
    credit_summary: null,
    credit_notes: [],
    movement_count: 1,
    items: [
        {
            id: 1,
            quantity: 1,
            credited_quantity: 0,
            remaining_quantity: 1,
            unit_price: 10,
            unit_cost_snapshot: 4,
            line_total: 10,
            cost_amount: 4,
            gross_margin: 6,
            prescription_code: null,
            approval_code: null,
            product_sku: 'PARA-500',
            lot_code: 'LOT-A',
        },
    ],
};
