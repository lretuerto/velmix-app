import { fireEvent, render, screen } from '@testing-library/react';
import { describe, expect, it, vi } from 'vitest';
import type { PricingQuote } from '@/modules/pricing/quotes/types';
import { PosQuotePreview } from '@/modules/pos/sales/components/PosQuotePreview';

describe('PosQuotePreview', () => {
    it('blocks checkout and offers requote recovery when the quote is expired', () => {
        const onRequote = vi.fn();

        render(
            <PosQuotePreview
                quote={makeQuote(new Date(Date.now() - 60_000).toISOString())}
                isConfirming={false}
                isRequoting={false}
                onConfirm={vi.fn()}
                onDiscard={vi.fn()}
                onRequote={onRequote}
            />,
        );

        expect(screen.getByText('Quote expirado')).toBeInTheDocument();
        expect(screen.getByRole('button', { name: 'Confirmar venta con quote' })).toBeDisabled();

        fireEvent.click(screen.getByRole('button', { name: 'Recotizar carrito' }));

        expect(onRequote).toHaveBeenCalledTimes(1);
    });

    it('keeps active quotes confirmable and exposes quote refresh', () => {
        const onConfirm = vi.fn();
        const onRequote = vi.fn();

        render(
            <PosQuotePreview
                quote={makeQuote(new Date(Date.now() + 5 * 60_000).toISOString())}
                isConfirming={false}
                isRequoting={false}
                onConfirm={onConfirm}
                onDiscard={vi.fn()}
                onRequote={onRequote}
            />,
        );

        fireEvent.click(screen.getByRole('button', { name: 'Confirmar venta con quote' }));
        fireEvent.click(screen.getByRole('button', { name: 'Actualizar quote' }));

        expect(onConfirm).toHaveBeenCalledTimes(1);
        expect(onRequote).toHaveBeenCalledTimes(1);
        expect(screen.queryByText('Quote expirado')).not.toBeInTheDocument();
    });
});

function makeQuote(expiresAt: string): PricingQuote {
    return {
        id: 900,
        status: 'quoted',
        quote_hash: 'sha256:quote-test',
        channel: 'retail',
        payment_method: 'cash',
        expires_at: expiresAt,
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
        items: [
            {
                id: 700,
                product_id: 2,
                product_sku: 'CLON-2',
                product_name: 'Clonazepam 2mg',
                requested_quantity: 2,
                resolved_price_list_item_id: 80,
                base_unit_price: 10,
                final_unit_price: 9,
                line_discount_amount: 2,
                line_total: 18,
                commercial_context: {
                    product: {
                        id: 2,
                        sku: 'CLON-2',
                        name: 'Clonazepam 2mg',
                        status: 'active',
                        commercial_status: 'active',
                        laboratory_supplier_id: null,
                    },
                    price_source: 'price_list',
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
}
