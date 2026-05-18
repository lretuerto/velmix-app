import { describe, expect, it } from 'vitest';
import { toPosSaleCreatePayloadFromQuote, toPricingQuoteCheckoutPayload, toPricingQuoteCreatePayload, posSaleCreateSchema } from '@/modules/pos/sales/schema';
import type { InventoryProduct } from '@/modules/inventory/products/types';
import type { PricingQuote } from '@/modules/pricing/quotes/types';

describe('posSaleCreateSchema', () => {
    it('maps form data into quote request and backend payload shape', () => {
        const parsed = posSaleCreateSchema.parse({
            payment_method: 'credit',
            customer_id: '15',
            due_at: '2026-05-10',
            items: [
                {
                    product_id: '2',
                    quantity: '3',
                    prescription_code: ' RX-001 ',
                    approval_code: '',
                },
            ],
        });

        const productsById = new Map<number, InventoryProduct>([[
            2,
            {
                id: 2,
                tenant_id: 10,
                sku: 'CLON-2',
                name: 'Clonazepam 2mg',
                status: 'active',
                is_controlled: true,
                last_cost: 0,
                average_cost: 0,
            },
        ]]);

        expect(toPricingQuoteCreatePayload(parsed)).toEqual({
            payment_method: 'credit',
            customer_id: 15,
            due_at: '2026-05-10',
            channel: 'retail',
            items: [
                {
                    product_id: 2,
                    quantity: 3,
                },
            ],
        });

        const quote: PricingQuote = {
            id: 500,
            status: 'quoted',
            quote_hash: 'sha256:test',
            channel: 'retail',
            payment_method: 'credit',
            expires_at: '2026-05-10T10:00:00Z',
            currency: 'PEN',
            customer: null,
            price_list: null,
            summary: {
                subtotal_amount: 13.5,
                discount_amount: 0,
                total_amount: 13.5,
            },
            items: [
                {
                    id: 1,
                    product_id: 2,
                    product_sku: 'CLON-2',
                    product_name: 'Clonazepam 2mg',
                    requested_quantity: 3,
                    resolved_price_list_item_id: 10,
                    base_unit_price: 4.5,
                    final_unit_price: 4.5,
                    line_discount_amount: 0,
                    line_total: 13.5,
                    commercial_context: {
                        product: {
                            id: 2,
                            sku: 'CLON-2',
                            name: 'Clonazepam 2mg',
                            status: 'active',
                            commercial_status: 'active',
                            laboratory_supplier_id: null,
                        },
                        price_source: 'tenant_default',
                        price_list: {
                            id: 10,
                            code: 'RETAIL-BASE',
                            channel: 'retail',
                            currency: 'PEN',
                        },
                    },
                    adjustments: [],
                },
            ],
            warnings: [],
            applied_promotions: [],
        };

        expect(toPosSaleCreatePayloadFromQuote(parsed, quote, productsById)).toEqual({
            payment_method: 'credit',
            customer_id: 15,
            due_at: '2026-05-10',
            items: [
                {
                    product_id: 2,
                    quantity: 3,
                    unit_price: 4.5,
                    prescription_code: 'RX-001',
                },
            ],
        });

        expect(toPricingQuoteCheckoutPayload(parsed, quote, productsById)).toEqual({
            quote_hash: 'sha256:test',
            due_at: '2026-05-10',
            line_inputs: [
                {
                    quote_item_id: 1,
                    prescription_code: 'RX-001',
                    approval_code: null,
                },
            ],
        });
    });

    it('requires customer for credit sales', () => {
        const result = posSaleCreateSchema.safeParse({
            payment_method: 'credit',
            customer_id: '',
            due_at: '',
            items: [
                {
                    product_id: '1',
                    quantity: '1',
                    prescription_code: '',
                    approval_code: '',
                },
            ],
        });

        expect(result.success).toBe(false);
    });
});
