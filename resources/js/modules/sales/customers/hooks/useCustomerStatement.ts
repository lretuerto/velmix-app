import { useQuery } from '@tanstack/react-query';
import { useAppShell } from '@/core/app/hooks';
import { fetchCustomerStatement } from '@/modules/sales/customers/api/customers';

interface UseCustomerStatementOptions {
    customerId: number | null;
}

export function useCustomerStatement({ customerId }: UseCustomerStatementOptions) {
    const boot = useAppShell();

    return useQuery({
        queryKey: ['sales-customers-statement', boot.tenant.selected?.id ?? 0, customerId ?? 0],
        queryFn: () => fetchCustomerStatement(customerId as number),
        enabled: boot.auth.authenticated && boot.tenant.selected !== null && customerId !== null,
    });
}
