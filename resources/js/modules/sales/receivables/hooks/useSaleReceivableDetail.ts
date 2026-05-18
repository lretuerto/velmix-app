import { useQuery } from '@tanstack/react-query';
import { useAppShell } from '@/core/app/hooks';
import { fetchSaleReceivableDetail } from '@/modules/sales/receivables/api/receivables';

interface UseSaleReceivableDetailOptions {
    receivableId: number | null;
}

export function useSaleReceivableDetail({ receivableId }: UseSaleReceivableDetailOptions) {
    const boot = useAppShell();

    return useQuery({
        queryKey: ['sales-receivable-detail', boot.tenant.selected?.id ?? 0, receivableId ?? 0],
        queryFn: () => fetchSaleReceivableDetail(receivableId as number),
        enabled: boot.auth.authenticated && boot.tenant.selected !== null && receivableId !== null,
    });
}
