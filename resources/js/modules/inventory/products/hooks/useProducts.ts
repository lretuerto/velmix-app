import { useQuery } from '@tanstack/react-query';
import { useAppShell } from '@/core/app/hooks';
import { fetchProducts } from '@/modules/inventory/products/api/products';

export function useProducts() {
    const boot = useAppShell();

    return useQuery({
        queryKey: ['inventory-products', boot.tenant.selected?.id ?? 0],
        queryFn: fetchProducts,
        enabled: boot.auth.authenticated && boot.tenant.selected !== null,
    });
}
