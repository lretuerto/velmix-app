export interface SalesCustomerSnapshot {
    id: number;
    document_type: string;
    document_number: string;
    name: string;
    phone: string | null;
    email: string | null;
    credit_limit: number | null;
    credit_days: number | null;
    block_on_overdue: boolean;
    status: string;
}

export interface SalesCustomer extends SalesCustomerSnapshot {
    outstanding_total: number;
    overdue_total: number;
    available_credit: number | null;
    credit_utilization_pct: number | null;
}

export interface SalesCustomerCreatePayload {
    document_type: string;
    document_number: string;
    name: string;
    phone: string | null;
    email: string | null;
    credit_limit: number | null;
    credit_days: number | null;
    block_on_overdue: boolean;
}

export interface SalesCustomerUpdatePayload extends SalesCustomerCreatePayload {
    status: string;
}

export type CustomerStatementCustomer = SalesCustomerSnapshot;

export interface CustomerStatementSummary {
    sales_total: number;
    receivables_total: number;
    payments_total: number;
    outstanding_total: number;
    available_credit: number | null;
    credit_utilization_pct: number | null;
    overdue_receivable_count: number;
    follow_up_count: number;
    promised_follow_up_count: number;
}

export interface CustomerStatementSale {
    id: number;
    reference: string;
    status: string;
    payment_method: string;
    total_amount: number;
    created_at: string | null;
}

export interface CustomerStatementReceivable {
    id: number;
    total_amount: number;
    paid_amount: number;
    outstanding_amount: number;
    status: string;
    due_at: string | null;
    sale_reference: string;
}

export interface CustomerStatementPayment {
    id: number;
    amount: number;
    payment_method: string;
    reference: string;
    paid_at: string | null;
    sale_reference: string;
}

export interface CustomerStatementFollowUp {
    id: number;
    sale_receivable_id: number;
    type: string;
    note: string;
    promised_amount: number | null;
    promised_at: string | null;
    created_at: string | null;
    sale_reference: string;
    user: {
        id: number;
        name: string;
    };
}

export interface CustomerStatement {
    customer: CustomerStatementCustomer;
    summary: CustomerStatementSummary;
    sales: CustomerStatementSale[];
    receivables: CustomerStatementReceivable[];
    payments: CustomerStatementPayment[];
    follow_ups: CustomerStatementFollowUp[];
}
