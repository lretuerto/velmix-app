import { useMutation, useQueryClient } from '@tanstack/react-query';
import { useAppShell } from '@/core/app/hooks';
import { createPricingQuote } from '@/modules/pricing/quotes/api/quotes';
import type { PricingQuote, PricingQuoteCreatePayload } from '@/modules/pricing/quotes/types';

interface CreatePricingQuoteMutationInput {
    payload: PricingQuoteCreatePayload;
    idempotencyKey?: string;
}

export function useCreatePricingQuote() {
    const boot = useAppShell();
    const queryClient = useQueryClient();

    return useMutation({
        mutationFn: ({ payload, idempotencyKey }: CreatePricingQuoteMutationInput) => createPricingQuote(payload, { idempotencyKey }),
        onSuccess: async (quote: PricingQuote) => {
            const tenantId = boot.tenant.selected?.id ?? 0;

            queryClient.setQueryData(['pricing-quote', tenantId, quote.id], quote);
        },
    });
}
