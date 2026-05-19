export interface CashSessionUserContext {
    id: number;
    name: string | null;
}

export interface CashSessionDenomination {
    value: number;
    quantity: number;
    subtotal: number;
}

export interface CashSessionSummary {
    id: number;
    tenant_id: number;
    status: string;
    opening_amount: number;
    expected_amount: number;
    counted_amount: number | null;
    discrepancy_amount: number | null;
    sales_count: number;
    sales_total: number;
    cash_sales_total: number;
    card_sales_total: number;
    transfer_sales_total: number;
    credit_sales_total: number;
    receivable_cash_total: number;
    refund_out_total: number;
    manual_in_total: number;
    manual_out_total: number;
    net_movement_total: number;
    movement_count: number;
    gross_cost_total: number;
    gross_margin_total: number;
    margin_pct: number;
    opened_by: CashSessionUserContext;
    closed_by: CashSessionUserContext | null;
    opened_at: string | null;
    closed_at: string | null;
    denominations?: CashSessionDenomination[];
}

export interface CashSessionOpenResult {
    id: number;
    tenant_id: number;
    status: string;
    opening_amount: number;
    opened_at: string | null;
}

export interface CashMovement {
    id: number;
    tenant_id: number;
    cash_session_id: number;
    created_by_user_id: number;
    type: string;
    amount: number;
    reference: string;
    notes: string | null;
    created_at: string | null;
}

export interface CashSessionOpenPayload {
    opening_amount: number;
}

export interface CashSessionClosePayload {
    counted_amount?: number | null;
    denominations?: Array<{
        value: number;
        quantity: number;
    }>;
}

export interface CashMovementCreatePayload {
    type: 'manual_in' | 'manual_out';
    amount: number;
    reference: string;
    notes?: string | null;
}
