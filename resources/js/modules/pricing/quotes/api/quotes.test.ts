import type { AxiosAdapter, AxiosResponse, InternalAxiosRequestConfig } from 'axios';
import { afterEach, describe, expect, it } from 'vitest';
import { apiClient } from '@/core/api/client';
import { checkoutPricingQuote, createPricingQuote } from '@/modules/pricing/quotes/api/quotes';
import type { PricingQuote, PricingQuoteCheckoutResult } from '@/modules/pricing/quotes/types';

describe('pricing quote API client', () => {
    const originalAdapter = apiClient.defaults.adapter;

    afterEach(() => {
        apiClient.defaults.adapter = originalAdapter;
    });

    it('creates pricing quotes against the OpenAPI route with an explicit idempotency key', async () => {
        const requests: InternalAxiosRequestConfig[] = [];

        apiClient.defaults.adapter = captureAdapter(requests, makeEnvelope(makeQuote()));

        const quote = await createPricingQuote(
            {
                payment_method: 'cash',
                customer_id: null,
                due_at: null,
                channel: 'retail',
                items: [
                    {
                        product_id: 2,
                        quantity: 3,
                    },
                ],
            },
            { idempotencyKey: 'idem-create-001' },
        );

        expect(quote.id).toBe(900);
        expect(requests).toHaveLength(1);
        expect(requests[0]?.method).toBe('post');
        expect(requests[0]?.url).toBe('/pricing/quotes');
        expect(readHeader(requests[0], 'Idempotency-Key')).toBe('idem-create-001');
    });

    it('checks out pricing quotes against the OpenAPI route with an explicit idempotency key', async () => {
        const requests: InternalAxiosRequestConfig[] = [];

        apiClient.defaults.adapter = captureAdapter(requests, makeEnvelope({
            quote: {
                id: 900,
                status: 'converted',
                quote_hash: 'sha256:quote-test',
                sale_id: 50,
                summary: {
                    subtotal_amount: 20,
                    discount_amount: 2,
                    total_amount: 18,
                },
                currency: 'PEN',
            },
            sale: {
                sale_id: 50,
                reference: 'POS-000050',
                payment_method: 'cash',
                total_amount: 18,
                gross_cost: 10,
                gross_margin: 8,
                customer: null,
                receivable: null,
                items: [],
            },
        } satisfies PricingQuoteCheckoutResult));

        const result = await checkoutPricingQuote(
            900,
            {
                quote_hash: 'sha256:quote-test',
                due_at: null,
                line_inputs: [
                    {
                        quote_item_id: 700,
                        prescription_code: 'RX-001',
                        approval_code: null,
                    },
                ],
            },
            { idempotencyKey: 'idem-checkout-001' },
        );

        expect(result.quote.sale_id).toBe(50);
        expect(requests).toHaveLength(1);
        expect(requests[0]?.method).toBe('post');
        expect(requests[0]?.url).toBe('/pricing/quotes/900/checkout');
        expect(readHeader(requests[0], 'Idempotency-Key')).toBe('idem-checkout-001');
    });
});

function captureAdapter<TData>(
    requests: InternalAxiosRequestConfig[],
    data: TData,
): AxiosAdapter {
    return async (config) => {
        requests.push(config);

        return {
            data,
            status: 200,
            statusText: 'OK',
            headers: {},
            config,
        } satisfies AxiosResponse<TData>;
    };
}

function makeEnvelope<TData>(data: TData): { data: TData } {
    return { data };
}

function readHeader(config: InternalAxiosRequestConfig, name: string): string | undefined {
    const headerValue = config.headers.get(name);

    if (Array.isArray(headerValue)) {
        return headerValue.join(', ');
    }

    if (headerValue === undefined || headerValue === null) {
        return undefined;
    }

    return String(headerValue);
}

function makeQuote(): PricingQuote {
    return {
        id: 900,
        status: 'quoted',
        quote_hash: 'sha256:quote-test',
        channel: 'retail',
        payment_method: 'cash',
        expires_at: '2026-05-06T12:00:00Z',
        currency: 'PEN',
        customer: null,
        price_list: {
            id: 10,
            code: 'RETAIL-BASE',
            name: 'Retail base',
            status: 'active',
            channel: 'retail',
            currency: 'PEN',
            priority: 100,
            is_default: true,
        },
        summary: {
            subtotal_amount: 20,
            discount_amount: 2,
            total_amount: 18,
        },
        items: [],
        warnings: [],
        applied_promotions: [],
    };
}
