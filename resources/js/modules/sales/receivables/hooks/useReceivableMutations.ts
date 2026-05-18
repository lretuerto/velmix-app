import { useMutation, useQueryClient } from '@tanstack/react-query';
import { useAppShell } from '@/core/app/hooks';
import {
    createSaleReceivableFollowUp,
    registerSaleReceivablePayment,
} from '@/modules/sales/receivables/api/receivables';
import type {
    SaleReceivableFollowUpPayload,
    SaleReceivablePaymentPayload,
} from '@/modules/sales/receivables/types';

export function useRegisterReceivablePayment() {
    const boot = useAppShell();
    const queryClient = useQueryClient();

    return useMutation({
        mutationFn: ({ receivableId, payload }: { receivableId: number; payload: SaleReceivablePaymentPayload }) =>
            registerSaleReceivablePayment(receivableId, payload),
        onSuccess: async (_result, variables) => {
            const tenantId = boot.tenant.selected?.id ?? 0;

            await invalidateReceivableSurface(queryClient, tenantId, variables.receivableId);
        },
    });
}

export function useCreateReceivableFollowUp() {
    const boot = useAppShell();
    const queryClient = useQueryClient();

    return useMutation({
        mutationFn: ({ receivableId, payload }: { receivableId: number; payload: SaleReceivableFollowUpPayload }) =>
            createSaleReceivableFollowUp(receivableId, payload),
        onSuccess: async (_result, variables) => {
            const tenantId = boot.tenant.selected?.id ?? 0;

            await invalidateReceivableSurface(queryClient, tenantId, variables.receivableId);
        },
    });
}

async function invalidateReceivableSurface(queryClient: ReturnType<typeof useQueryClient>, tenantId: number, receivableId: number) {
    await Promise.all([
        queryClient.invalidateQueries({ queryKey: ['sales-receivables', tenantId] }),
        queryClient.invalidateQueries({ queryKey: ['sales-receivables-aging', tenantId] }),
        queryClient.invalidateQueries({ queryKey: ['sales-receivable-detail', tenantId, receivableId] }),
        queryClient.invalidateQueries({ queryKey: ['sales-customers', tenantId] }),
        queryClient.invalidateQueries({ queryKey: ['sales-customers-statement', tenantId] }),
        queryClient.invalidateQueries({ queryKey: ['cash-current-session', tenantId] }),
        queryClient.invalidateQueries({ queryKey: ['cash-session-history', tenantId] }),
        queryClient.invalidateQueries({ queryKey: ['cash-session-detail', tenantId] }),
    ]);
}
