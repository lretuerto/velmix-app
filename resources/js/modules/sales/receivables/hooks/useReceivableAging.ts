import { useQuery } from '@tanstack/react-query';
import { useAppShell } from '@/core/app/hooks';
import { fetchReceivableAgingSummary } from '@/modules/sales/receivables/api/receivables';

export function useReceivableAging() {
    const boot = useAppShell();

    return useQuery({
        queryKey: ['sales-receivables-aging', boot.tenant.selected?.id ?? 0],
        queryFn: fetchReceivableAgingSummary,
        enabled: boot.auth.authenticated && boot.tenant.selected !== null,
    });
}
