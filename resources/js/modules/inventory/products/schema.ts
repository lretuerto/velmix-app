import { z } from 'zod';

export const productCreateSchema = z.object({
    sku: z
        .string()
        .trim()
        .min(1, 'El SKU es obligatorio.')
        .max(80, 'El SKU no puede exceder 80 caracteres.'),
    name: z
        .string()
        .trim()
        .min(1, 'El nombre es obligatorio.')
        .max(160, 'El nombre no puede exceder 160 caracteres.'),
    is_controlled: z.boolean(),
});

export type ProductCreateFormData = z.infer<typeof productCreateSchema>;
