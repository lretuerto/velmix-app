import { useQuery } from '@tanstack/react-query';
import { useAppShell } from '@/core/app/hooks';
import { fetchCashSessionMovements } from '@/modules/cash/sessions/api/cashSessions';

interface UseCashSessionMovementsOptions {
    sessionId: number | null;
    enabled?: boolean;
}

export function useCashSessionMovements({ sessionId, enabled = true }: UseCashSessionMovementsOptions) {
    const boot = useAppShell();

    return useQuery({
        queryKey: ['cash-session-movements', boot.tenant.selected?.id ?? 0, sessionId ?? 0],
        queryFn: () => fetchCashSessionMovements(sessionId as number),
        enabled: enabled && boot.auth.authenticated && boot.tenant.selected !== null && sessionId !== null,
    });
}
