import { useQuery } from '@tanstack/react-query';
import { useAppShell } from '@/core/app/hooks';
import { fetchCashSessionHistory } from '@/modules/cash/sessions/api/cashSessions';

export function useCashSessionHistory() {
    const boot = useAppShell();

    return useQuery({
        queryKey: ['cash-session-history', boot.tenant.selected?.id ?? 0],
        queryFn: fetchCashSessionHistory,
        enabled: boot.auth.authenticated && boot.tenant.selected !== null,
    });
}
