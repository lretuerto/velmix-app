import { createIdempotencyKey, getJson, postJson } from '@/core/api/client';
import type {
    PosSaleCreatePayload,
    PosSaleCreated,
    PosSaleDetail,
    PosSaleSummary,
} from '@/modules/pos/sales/types';

export async function fetchPosSales(): Promise<PosSaleSummary[]> {
    return getJson<PosSaleSummary[]>('/pos/sales');
}

export async function fetchPosSaleDetail(saleId: number): Promise<PosSaleDetail> {
    return getJson<PosSaleDetail>(`/pos/sales/${saleId}`);
}

export async function createPosSale(payload: PosSaleCreatePayload): Promise<PosSaleCreated> {
    return postJson<PosSaleCreated, PosSaleCreatePayload>('/pos/sales', payload, {
        headers: {
            'Idempotency-Key': createIdempotencyKey('pos-sale-create'),
        },
    });
}
