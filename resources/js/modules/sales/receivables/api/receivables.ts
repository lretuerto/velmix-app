import { createIdempotencyKey, getJson, postJson } from '@/core/api/client';
import type {
    ReceivableAgingSummary,
    SaleReceivableDetail,
    SaleReceivableFollowUp,
    SaleReceivableFollowUpPayload,
    SaleReceivablePaymentPayload,
    SaleReceivableSummary,
} from '@/modules/sales/receivables/types';

export async function fetchSaleReceivables(): Promise<SaleReceivableSummary[]> {
    return getJson<SaleReceivableSummary[]>('/sales/receivables');
}

export async function fetchReceivableAgingSummary(): Promise<ReceivableAgingSummary> {
    return getJson<ReceivableAgingSummary>('/sales/receivables/aging');
}

export async function fetchSaleReceivableDetail(receivableId: number): Promise<SaleReceivableDetail> {
    return getJson<SaleReceivableDetail>(`/sales/receivables/${receivableId}`);
}

export async function registerSaleReceivablePayment(
    receivableId: number,
    payload: SaleReceivablePaymentPayload,
): Promise<{
    payment_id: number;
    sale_receivable_id: number;
    amount: number;
    payment_method: string;
    reference: string;
    cash_movement_id: number | null;
    paid_amount: number;
    outstanding_amount: number;
    status: string;
}> {
    return postJson(`/sales/receivables/${receivableId}/payments`, payload, {
        headers: {
            'Idempotency-Key': createIdempotencyKey('sales-receivable-payment'),
        },
    });
}

export async function createSaleReceivableFollowUp(
    receivableId: number,
    payload: SaleReceivableFollowUpPayload,
): Promise<SaleReceivableFollowUp> {
    return postJson<SaleReceivableFollowUp, SaleReceivableFollowUpPayload>(
        `/sales/receivables/${receivableId}/follow-ups`,
        payload,
    );
}
