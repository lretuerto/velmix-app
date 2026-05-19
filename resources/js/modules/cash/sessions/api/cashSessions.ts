import { ApiError } from '@/core/api/errors';
import { createIdempotencyKey, getJson, postJson } from '@/core/api/client';
import type {
    CashMovement,
    CashMovementCreatePayload,
    CashSessionClosePayload,
    CashSessionOpenPayload,
    CashSessionOpenResult,
    CashSessionSummary,
} from '@/modules/cash/sessions/types';

export async function fetchCurrentCashSession(): Promise<CashSessionSummary | null> {
    try {
        return await getJson<CashSessionSummary>('/cash/sessions/current');
    } catch (error) {
        if (error instanceof ApiError && error.status === 404) {
            return null;
        }

        throw error;
    }
}

export async function fetchCashSessionHistory(): Promise<CashSessionSummary[]> {
    return getJson<CashSessionSummary[]>('/cash/sessions');
}

export async function fetchCashSessionDetail(sessionId: number): Promise<CashSessionSummary> {
    return getJson<CashSessionSummary>(`/cash/sessions/${sessionId}`);
}

export async function fetchCashSessionMovements(sessionId: number): Promise<CashMovement[]> {
    return getJson<CashMovement[]>(`/cash/sessions/${sessionId}/movements`);
}

export async function openCashSession(payload: CashSessionOpenPayload): Promise<CashSessionOpenResult> {
    return postJson<CashSessionOpenResult, CashSessionOpenPayload>('/cash/sessions/open', payload, {
        headers: {
            'Idempotency-Key': createIdempotencyKey('cash-session-open'),
        },
    });
}

export async function closeCurrentCashSession(payload: CashSessionClosePayload): Promise<CashSessionSummary> {
    return postJson<CashSessionSummary, CashSessionClosePayload>('/cash/sessions/current/close', payload, {
        headers: {
            'Idempotency-Key': createIdempotencyKey('cash-session-close'),
        },
    });
}

export async function createCashMovement(payload: CashMovementCreatePayload): Promise<CashMovement> {
    return postJson<CashMovement, CashMovementCreatePayload>('/cash/movements', payload, {
        headers: {
            'Idempotency-Key': createIdempotencyKey('cash-movement-create'),
        },
    });
}
