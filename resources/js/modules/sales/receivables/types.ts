export interface SaleReceivableSummaryCustomer {
    document_type: string;
    document_number: string;
    name: string;
}

export interface SaleReceivableSummary {
    id: number;
    total_amount: number;
    paid_amount: number;
    outstanding_amount: number;
    status: string;
    effective_status: string;
    aging_bucket: string;
    due_at: string | null;
    customer: SaleReceivableSummaryCustomer;
    sale_reference: string;
}

export interface SaleReceivableDetailCustomer extends SaleReceivableSummaryCustomer {
    id: number;
}

export interface SaleReceivableDetailSale {
    id: number;
    reference: string;
}

export interface SaleReceivablePayment {
    id: number;
    amount: number;
    payment_method: string;
    reference: string;
    paid_at: string | null;
}

export interface SaleReceivableFollowUp {
    id: number;
    type: string;
    note: string;
    promised_amount: number | null;
    outstanding_snapshot: number | null;
    promised_at: string | null;
    created_at: string | null;
    user: {
        id: number;
        name: string;
    };
}

export interface SaleReceivableDetail {
    id: number;
    total_amount: number;
    paid_amount: number;
    outstanding_amount: number;
    status: string;
    effective_status: string;
    aging_bucket: string;
    due_at: string | null;
    customer: SaleReceivableDetailCustomer;
    sale: SaleReceivableDetailSale;
    payments: SaleReceivablePayment[];
    latest_follow_up: SaleReceivableFollowUp | null;
    follow_ups: SaleReceivableFollowUp[];
}

export interface ReceivableAgingBucketSummary {
    count: number;
    amount: number;
}

export interface ReceivableAgingSummary {
    tenant_id: number;
    summary: {
        current: ReceivableAgingBucketSummary;
        overdue_1_30: ReceivableAgingBucketSummary;
        overdue_31_60: ReceivableAgingBucketSummary;
        overdue_61_plus: ReceivableAgingBucketSummary;
        paid: ReceivableAgingBucketSummary;
    };
}

export interface SaleReceivablePaymentPayload {
    amount: number;
    payment_method: 'cash' | 'card' | 'transfer' | 'bank_transfer';
    reference: string;
}

export interface SaleReceivableFollowUpPayload {
    type: 'note' | 'promise';
    note: string;
    promised_amount: number | null;
    promised_at: string | null;
}
