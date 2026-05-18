import { useMutation, useQueryClient } from '@tanstack/react-query';
import { useAppShell } from '@/core/app/hooks';
import { checkoutPricingQuote } from '@/modules/pricing/quotes/api/quotes';
import type { PricingQuote, PricingQuoteCheckoutPayload, PricingQuoteCheckoutResult } from '@/modules/pricing/quotes/types';
import type { PosSaleDetail } from '@/modules/pos/sales/types';

interface CheckoutPricingQuoteMutationInput {
    quoteId: number;
    payload: PricingQuoteCheckoutPayload;
    idempotencyKey?: string;
}

export function useCheckoutPricingQuote() {
    const boot = useAppShell();
    const queryClient = useQueryClient();

    return useMutation({
        mutationFn: ({ quoteId, payload, idempotencyKey }: CheckoutPricingQuoteMutationInput) => (
            checkoutPricingQuote(quoteId, payload, { idempotencyKey })
        ),
        onSuccess: async (result: PricingQuoteCheckoutResult) => {
            const tenantId = boot.tenant.selected?.id ?? 0;
            const sale = result.sale;

            await Promise.all([
                queryClient.invalidateQueries({ queryKey: ['pos-sales', tenantId] }),
                queryClient.invalidateQueries({ queryKey: ['sales-receivables', tenantId] }),
                queryClient.invalidateQueries({ queryKey: ['sales-receivables-aging', tenantId] }),
                queryClient.invalidateQueries({ queryKey: ['sales-customers', tenantId] }),
                queryClient.invalidateQueries({ queryKey: ['sales-customers-statement', tenantId] }),
                queryClient.invalidateQueries({ queryKey: ['cash-current-session', tenantId] }),
                queryClient.invalidateQueries({ queryKey: ['cash-session-history', tenantId] }),
                queryClient.invalidateQueries({ queryKey: ['cash-session-detail', tenantId] }),
            ]);

            queryClient.setQueryData<PricingQuote | undefined>(['pricing-quote', tenantId, result.quote.id], (current) => {
                if (current === undefined) {
                    return current;
                }

                return {
                    ...current,
                    status: result.quote.status,
                };
            });

            queryClient.setQueryData<PosSaleDetail | undefined>(
                ['pos-sale-detail', tenantId, sale.sale_id],
                {
                    id: sale.sale_id,
                    reference: sale.reference,
                    status: 'completed',
                    payment_method: sale.payment_method,
                    total_amount: sale.total_amount,
                    gross_cost: sale.gross_cost,
                    gross_margin: sale.gross_margin,
                    cancel_reason: null,
                    cancelled_at: null,
                    credit_reason: null,
                    credited_at: null,
                    customer: sale.customer,
                    receivable: sale.receivable,
                    voucher: null,
                    credit_note: null,
                    credit_summary: null,
                    credit_notes: [],
                    movement_count: sale.items.reduce((carry, item) => carry + item.allocations.length, 0),
                    items: sale.items.map((item, index) => ({
                        id: index + 1,
                        quantity: item.quantity,
                        credited_quantity: 0,
                        remaining_quantity: item.quantity,
                        unit_price: item.unit_price,
                        unit_cost_snapshot: item.unit_cost_snapshot,
                        line_total: item.line_total,
                        cost_amount: item.cost_amount,
                        gross_margin: item.gross_margin,
                        prescription_code: item.prescription_code ?? null,
                        approval_code: item.approval_code ?? null,
                        product_sku: item.product_sku,
                        lot_code: item.allocations[0]?.lot_code ?? 'FIFO',
                    })),
                },
            );
        },
    });
}
