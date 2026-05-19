import { useMutation, useQueryClient } from '@tanstack/react-query';
import { useAppShell } from '@/core/app/hooks';
import { createProduct } from '@/modules/inventory/products/api/products';
import type {
    InventoryProduct,
    InventoryProductCreatePayload,
} from '@/modules/inventory/products/types';

export function useCreateProduct() {
    const boot = useAppShell();
    const queryClient = useQueryClient();

    return useMutation({
        mutationFn: (payload: InventoryProductCreatePayload) => createProduct(payload),
        onSuccess: (product: InventoryProduct) => {
            void queryClient.invalidateQueries({
                queryKey: ['inventory-products', boot.tenant.selected?.id ?? 0],
            });

            queryClient.setQueryData<InventoryProduct[] | undefined>(
                ['inventory-products', boot.tenant.selected?.id ?? 0],
                (current) => {
                    if (current === undefined) {
                        return [product];
                    }

                    const withoutDuplicate = current.filter((item) => item.id !== product.id);

                    return [...withoutDuplicate, product].sort((left, right) => left.sku.localeCompare(right.sku));
                },
            );
        },
    });
}
