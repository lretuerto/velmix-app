import { useQuery } from '@tanstack/react-query';
import { useAppShell } from '@/core/app/hooks';
import { fetchPosSales } from '@/modules/pos/sales/api/sales';

export function usePosSales() {
    const boot = useAppShell();

    return useQuery({
        queryKey: ['pos-sales', boot.tenant.selected?.id ?? 0],
        queryFn: fetchPosSales,
        enabled: boot.auth.authenticated && boot.tenant.selected !== null,
    });
}
