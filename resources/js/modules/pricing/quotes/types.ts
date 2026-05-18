import type { PosSaleCreated } from '@/modules/pos/sales/types';

export interface PricingQuoteCustomer {
    id: number;
    document_type: string;
    document_number: string;
    name: string;
    status: string;
}

export interface PricingQuotePriceList {
    id: number;
    code: string;
    name: string;
    status: string;
    channel: string;
    currency: string;
    priority: number;
    is_default: boolean;
}

export interface PricingQuoteAdjustmentSponsorSupplier {
    id: number;
    name: string | null;
}

export interface PricingQuoteAdjustment {
    id: number;
    type: 'base_price' | 'promotion_discount' | 'manual_override';
    description: string;
    promotion_id: number | null;
    promotion_rule_id: number | null;
    promotion_code: string | null;
    promotion_name: string | null;
    sponsor_supplier: PricingQuoteAdjustmentSponsorSupplier | null;
    quantity: number;
    unit_delta: number;
    total_delta: number;
    metadata: Record<string, unknown>;
}

export interface PricingQuoteCommercialContext {
    product: {
        id: number;
        sku: string;
        name: string;
        status: string;
        commercial_status: string;
        laboratory_supplier_id: number | null;
    };
    price_source: string;
    price_list: {
        id: number;
        code: string;
        channel: string;
        currency: string;
    };
}

export interface PricingQuoteItem {
    id: number;
    product_id: number;
    product_sku: string;
    product_name: string;
    requested_quantity: number;
    resolved_price_list_item_id: number | null;
    base_unit_price: number;
    final_unit_price: number;
    line_discount_amount: number;
    line_total: number;
    commercial_context: PricingQuoteCommercialContext;
    adjustments: PricingQuoteAdjustment[];
}

export interface PricingQuoteAppliedPromotion {
    id: number;
    code: string | null;
    name: string | null;
    discount_amount: number;
    sponsor_supplier: PricingQuoteAdjustmentSponsorSupplier | null;
}

export interface PricingQuote {
    id: number;
    status: string;
    quote_hash: string;
    channel: string;
    payment_method: 'cash' | 'card' | 'transfer' | 'credit';
    expires_at: string;
    currency: string;
    created_at?: string;
    updated_at?: string;
    customer: PricingQuoteCustomer | null;
    price_list: PricingQuotePriceList | null;
    summary: {
        subtotal_amount: number;
        discount_amount: number;
        total_amount: number;
    };
    items: PricingQuoteItem[];
    warnings: string[];
    applied_promotions: PricingQuoteAppliedPromotion[];
}

export interface PricingQuoteCreatePayload {
    payment_method: 'cash' | 'card' | 'transfer' | 'credit';
    customer_id?: number | null;
    due_at?: string | null;
    channel?: 'retail' | 'wholesale' | 'institutional' | 'mixed';
    items: Array<{
        product_id: number;
        quantity: number;
    }>;
}

export interface PricingQuoteCheckoutPayload {
    quote_hash: string;
    due_at?: string | null;
    line_inputs?: Array<{
        quote_item_id: number;
        prescription_code?: string | null;
        approval_code?: string | null;
    }>;
}

export interface PricingQuoteCheckoutResult {
    quote: {
        id: number;
        status: string;
        quote_hash: string;
        sale_id: number;
        summary: {
            subtotal_amount: number;
            discount_amount: number;
            total_amount: number;
        };
        currency: string;
    };
    sale: PosSaleCreated;
}
