import { createIdempotencyKey, getJson, postJson } from '@/core/api/client';
import type {
    PricingQuote,
    PricingQuoteCheckoutPayload,
    PricingQuoteCheckoutResult,
    PricingQuoteCreatePayload,
} from '@/modules/pricing/quotes/types';

interface IdempotentRequestOptions {
    idempotencyKey?: string;
}

export async function createPricingQuote(
    payload: PricingQuoteCreatePayload,
    options: IdempotentRequestOptions = {},
): Promise<PricingQuote> {
    return postJson<PricingQuote, PricingQuoteCreatePayload>('/pricing/quotes', payload, {
        headers: {
            'Idempotency-Key': options.idempotencyKey ?? createIdempotencyKey('pricing-quote-create'),
        },
    });
}

export async function fetchPricingQuote(quoteId: number): Promise<PricingQuote> {
    return getJson<PricingQuote>(`/pricing/quotes/${quoteId}`);
}

export async function checkoutPricingQuote(
    quoteId: number,
    payload: PricingQuoteCheckoutPayload,
    options: IdempotentRequestOptions = {},
): Promise<PricingQuoteCheckoutResult> {
    return postJson<PricingQuoteCheckoutResult, PricingQuoteCheckoutPayload>(`/pricing/quotes/${quoteId}/checkout`, payload, {
        headers: {
            'Idempotency-Key': options.idempotencyKey ?? createIdempotencyKey('pricing-quote-checkout'),
        },
    });
}
