export interface PosSaleSummaryCustomer {
    id: number;
    document_type: string;
    document_number: string;
    name: string;
}

export interface PosSaleSummaryReceivable {
    id: number;
    status: string;
    outstanding_amount: number;
}

export interface PosSaleSummaryCreditNote {
    id: number;
    status: string;
}

export interface PosSaleSummaryCreditSummary {
    count: number;
    credited_total: number;
    refunded_total: number;
    latest_id: number;
    latest_status: string;
    latest_series: string;
    latest_number: number;
}

export interface PosSaleSummary {
    id: number;
    reference: string;
    status: string;
    payment_method: string;
    total_amount: number;
    gross_cost: number;
    gross_margin: number;
    cancel_reason: string | null;
    cancelled_at: string | null;
    credit_reason: string | null;
    credited_at: string | null;
    customer: PosSaleSummaryCustomer | null;
    receivable: PosSaleSummaryReceivable | null;
    voucher_id: number | null;
    voucher_status: string | null;
    credit_note: PosSaleSummaryCreditNote | null;
    credit_summary: PosSaleSummaryCreditSummary | null;
}

export interface PosSaleDetailItemAllocation {
    lot_id: number;
    lot_code: string;
    quantity: number;
    remaining_stock: number;
}

export interface PosSaleCreatedItem {
    product_id: number;
    product_sku: string;
    quantity: number;
    unit_price: number;
    unit_cost_snapshot: number;
    line_total: number;
    cost_amount: number;
    gross_margin: number;
    prescription_code: string | null;
    approval_code: string | null;
    allocations: PosSaleDetailItemAllocation[];
}

export interface PosSaleCreated {
    sale_id: number;
    reference: string;
    payment_method: string;
    customer: PosSaleSummaryCustomer | null;
    receivable: {
        id: number;
        status: string;
        due_at: string | null;
        outstanding_amount: number;
    } | null;
    total_amount: number;
    gross_cost: number;
    gross_margin: number;
    items: PosSaleCreatedItem[];
}

export interface PosSaleDetailReceivable {
    id: number;
    status: string;
    due_at: string | null;
    outstanding_amount: number;
}

export interface PosSaleDetailVoucher {
    id: number;
    status: string;
    series: string;
    number: number;
}

export interface PosSaleDetailCreditNote {
    id: number;
    status: string;
    series: string;
    number: number;
}

export interface PosSaleDetailCreditNoteRecord {
    id: number;
    series: string;
    number: number;
    status: string;
    reason: string;
    total_amount: number;
    refunded_amount: number;
    refund_payment_method: string | null;
    created_at: string | null;
}

export interface PosSaleDetailItem {
    id: number;
    quantity: number;
    credited_quantity: number;
    remaining_quantity: number;
    unit_price: number;
    unit_cost_snapshot: number;
    line_total: number;
    cost_amount: number;
    gross_margin: number;
    prescription_code: string | null;
    approval_code: string | null;
    product_sku: string;
    lot_code: string;
}

export interface PosSaleDetail {
    id: number;
    reference: string;
    status: string;
    payment_method: string;
    total_amount: number;
    gross_cost: number;
    gross_margin: number;
    cancel_reason: string | null;
    cancelled_at: string | null;
    credit_reason: string | null;
    credited_at: string | null;
    customer: PosSaleSummaryCustomer | null;
    receivable: PosSaleDetailReceivable | null;
    voucher: PosSaleDetailVoucher | null;
    credit_note: PosSaleDetailCreditNote | null;
    credit_summary: PosSaleSummaryCreditSummary | null;
    credit_notes: PosSaleDetailCreditNoteRecord[];
    movement_count: number;
    items: PosSaleDetailItem[];
}

export interface PosSaleCreateLinePayload {
    product_id: number;
    quantity: number;
    unit_price: number;
    prescription_code?: string | null;
    approval_code?: string | null;
}

export interface PosSaleCreatePayload {
    payment_method: 'cash' | 'card' | 'transfer' | 'credit';
    customer_id?: number | null;
    due_at?: string | null;
    items: PosSaleCreateLinePayload[];
}
