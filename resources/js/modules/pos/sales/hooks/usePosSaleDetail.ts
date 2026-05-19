import { useQuery } from '@tanstack/react-query';
import { useAppShell } from '@/core/app/hooks';
import { fetchPosSaleDetail } from '@/modules/pos/sales/api/sales';

interface UsePosSaleDetailOptions {
    saleId: number | null;
}

export function usePosSaleDetail({ saleId }: UsePosSaleDetailOptions) {
    const boot = useAppShell();

    return useQuery({
        queryKey: ['pos-sale-detail', boot.tenant.selected?.id ?? 0, saleId ?? 0],
        queryFn: () => fetchPosSaleDetail(saleId as number),
        enabled: boot.auth.authenticated && boot.tenant.selected !== null && saleId !== null,
    });
}
