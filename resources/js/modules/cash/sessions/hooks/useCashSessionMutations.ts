import { useMutation, useQueryClient } from '@tanstack/react-query';
import { useAppShell } from '@/core/app/hooks';
import {
    closeCurrentCashSession,
    createCashMovement,
    openCashSession,
} from '@/modules/cash/sessions/api/cashSessions';
import type {
    CashMovementCreatePayload,
    CashSessionClosePayload,
    CashSessionOpenPayload,
} from '@/modules/cash/sessions/types';

export function useOpenCashSession() {
    const boot = useAppShell();
    const queryClient = useQueryClient();

    return useMutation({
        mutationFn: (payload: CashSessionOpenPayload) => openCashSession(payload),
        onSuccess: async () => {
            const tenantId = boot.tenant.selected?.id ?? 0;

            await Promise.all([
                queryClient.invalidateQueries({ queryKey: ['cash-current-session', tenantId] }),
                queryClient.invalidateQueries({ queryKey: ['cash-session-history', tenantId] }),
            ]);
        },
    });
}

export function useCloseCashSession() {
    const boot = useAppShell();
    const queryClient = useQueryClient();

    return useMutation({
        mutationFn: (payload: CashSessionClosePayload) => closeCurrentCashSession(payload),
        onSuccess: async () => {
            const tenantId = boot.tenant.selected?.id ?? 0;

            await Promise.all([
                queryClient.invalidateQueries({ queryKey: ['cash-current-session', tenantId] }),
                queryClient.invalidateQueries({ queryKey: ['cash-session-history', tenantId] }),
                queryClient.invalidateQueries({ queryKey: ['cash-session-detail', tenantId] }),
                queryClient.invalidateQueries({ queryKey: ['cash-session-movements', tenantId] }),
            ]);
        },
    });
}

export function useCreateCashMovement() {
    const boot = useAppShell();
    const queryClient = useQueryClient();

    return useMutation({
        mutationFn: (payload: CashMovementCreatePayload) => createCashMovement(payload),
        onSuccess: async (movement) => {
            const tenantId = boot.tenant.selected?.id ?? 0;

            await Promise.all([
                queryClient.invalidateQueries({ queryKey: ['cash-current-session', tenantId] }),
                queryClient.invalidateQueries({ queryKey: ['cash-session-history', tenantId] }),
                queryClient.invalidateQueries({ queryKey: ['cash-session-detail', tenantId, movement.cash_session_id] }),
                queryClient.invalidateQueries({ queryKey: ['cash-session-movements', tenantId, movement.cash_session_id] }),
            ]);
        },
    });
}
