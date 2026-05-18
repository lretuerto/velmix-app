import { createIdempotencyKey, getJson, postJson } from '@/core/api/client';
import type {
    InventoryProduct,
    InventoryProductCreatePayload,
} from '@/modules/inventory/products/types';

export async function fetchProducts(): Promise<InventoryProduct[]> {
    return getJson<InventoryProduct[]>('/inventory/products');
}

export async function createProduct(payload: InventoryProductCreatePayload): Promise<InventoryProduct> {
    return postJson<InventoryProduct, InventoryProductCreatePayload>('/inventory/products', payload, {
        headers: {
            'Idempotency-Key': createIdempotencyKey('inventory-product-create'),
        },
    });
}
