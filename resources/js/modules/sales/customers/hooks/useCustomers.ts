import { useQuery } from '@tanstack/react-query';
import { useAppShell } from '@/core/app/hooks';
import { fetchCustomers } from '@/modules/sales/customers/api/customers';

export function useCustomers() {
    const boot = useAppShell();

    return useQuery({
        queryKey: ['sales-customers', boot.tenant.selected?.id ?? 0],
        queryFn: fetchCustomers,
        enabled: boot.auth.authenticated && boot.tenant.selected !== null,
    });
}
