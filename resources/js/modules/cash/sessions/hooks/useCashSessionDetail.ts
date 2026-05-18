import { useQuery } from '@tanstack/react-query';
import { useAppShell } from '@/core/app/hooks';
import { fetchCashSessionDetail } from '@/modules/cash/sessions/api/cashSessions';

interface UseCashSessionDetailOptions {
    sessionId: number | null;
}

export function useCashSessionDetail({ sessionId }: UseCashSessionDetailOptions) {
    const boot = useAppShell();

    return useQuery({
        queryKey: ['cash-session-detail', boot.tenant.selected?.id ?? 0, sessionId ?? 0],
        queryFn: () => fetchCashSessionDetail(sessionId as number),
        enabled: boot.auth.authenticated && boot.tenant.selected !== null && sessionId !== null,
    });
}
