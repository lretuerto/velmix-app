import { useQuery } from '@tanstack/react-query';
import { useAppShell } from '@/core/app/hooks';
import { fetchSaleReceivables } from '@/modules/sales/receivables/api/receivables';

export function useSaleReceivables() {
    const boot = useAppShell();

    return useQuery({
        queryKey: ['sales-receivables', boot.tenant.selected?.id ?? 0],
        queryFn: fetchSaleReceivables,
        enabled: boot.auth.authenticated && boot.tenant.selected !== null,
    });
}
