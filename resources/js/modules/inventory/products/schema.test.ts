import { describe, expect, it } from 'vitest';
import { productCreateSchema } from '@/modules/inventory/products/schema';

describe('productCreateSchema', () => {
    it('accepts a valid product payload', () => {
        const result = productCreateSchema.safeParse({
            sku: 'IBUP-400',
            name: 'Ibuprofeno 400mg',
            is_controlled: false,
        });

        expect(result.success).toBe(true);
    });

    it('rejects empty sku values', () => {
        const result = productCreateSchema.safeParse({
            sku: '',
            name: 'Producto sin sku',
            is_controlled: false,
        });

        expect(result.success).toBe(false);
    });
});
