import { useQuery } from '@tanstack/react-query';
import { useAppShell } from '@/core/app/hooks';
import { fetchCurrentCashSession } from '@/modules/cash/sessions/api/cashSessions';

export function useCurrentCashSession() {
    const boot = useAppShell();

    return useQuery({
        queryKey: ['cash-current-session', boot.tenant.selected?.id ?? 0],
        queryFn: fetchCurrentCashSession,
        enabled: boot.auth.authenticated && boot.tenant.selected !== null,
    });
}
