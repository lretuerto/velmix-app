import { z } from 'zod';
import type { InventoryProduct } from '@/modules/inventory/products/types';
import type { PricingQuote, PricingQuoteCheckoutPayload, PricingQuoteCreatePayload } from '@/modules/pricing/quotes/types';
import type { PosSaleCreatePayload } from '@/modules/pos/sales/types';

export const posSaleLineSchema = z.object({
    product_id: z
        .string()
        .trim()
        .min(1, 'Selecciona un producto.'),
    quantity: z
        .string()
        .trim()
        .refine((value) => isPositiveInteger(value), {
            message: 'La cantidad debe ser un entero mayor a 0.',
        }),
    prescription_code: z.string().trim(),
    approval_code: z.string().trim(),
});

export const posSaleCreateSchema = z.object({
    payment_method: z.enum(['cash', 'card', 'transfer', 'credit']),
    customer_id: z.string().trim(),
    due_at: z.string().trim(),
    items: z.array(posSaleLineSchema).min(1, 'Agrega al menos una linea de venta.'),
}).superRefine((values, context) => {
    if (values.payment_method === 'credit' && values.customer_id === '') {
        context.addIssue({
            code: z.ZodIssueCode.custom,
            path: ['customer_id'],
            message: 'La venta a credito requiere cliente.',
        });
    }
});

export type PosSaleCreateFormData = z.infer<typeof posSaleCreateSchema>;

export const defaultPosSaleLineValues: PosSaleCreateFormData['items'][number] = {
    product_id: '',
    quantity: '1',
    prescription_code: '',
    approval_code: '',
};

export const defaultPosSaleCreateValues: PosSaleCreateFormData = {
    payment_method: 'cash',
    customer_id: '',
    due_at: '',
    items: [defaultPosSaleLineValues],
};

export function toPricingQuoteCreatePayload(values: PosSaleCreateFormData): PricingQuoteCreatePayload {
    return {
        payment_method: values.payment_method,
        customer_id: values.payment_method === 'credit' ? emptyStringToInteger(values.customer_id) : null,
        due_at: values.payment_method === 'credit' ? emptyStringToNull(values.due_at) : null,
        channel: 'retail',
        items: values.items.map((item) => ({
            product_id: Number.parseInt(item.product_id, 10),
            quantity: Number.parseInt(item.quantity, 10),
        })),
    };
}

export function toPosSaleCreatePayloadFromQuote(
    values: PosSaleCreateFormData,
    quote: PricingQuote,
    productsById: Map<number, InventoryProduct>,
): PosSaleCreatePayload {
    if (values.items.length !== quote.items.length) {
        throw new Error('La cotizacion ya no coincide con las lineas actuales. Vuelve a cotizar antes de vender.');
    }

    const items = values.items.map((item, index) => {
        const productId = Number.parseInt(item.product_id, 10);
        const quoteItem = quote.items[index];

        if (quoteItem === undefined || quoteItem.product_id !== productId) {
            throw new Error('La cotizacion no coincide con los productos actuales. Vuelve a cotizar antes de vender.');
        }

        const payloadItem = {
            product_id: productId,
            quantity: Number.parseInt(item.quantity, 10),
            unit_price: quoteItem.final_unit_price,
        } as PosSaleCreatePayload['items'][number];
        const product = productsById.get(productId);
        const prescriptionCode = emptyStringToNull(item.prescription_code);
        const approvalCode = emptyStringToNull(item.approval_code);

        if (product?.is_controlled === true) {
            if (prescriptionCode !== null) {
                payloadItem.prescription_code = prescriptionCode;
            }

            if (approvalCode !== null) {
                payloadItem.approval_code = approvalCode;
            }
        }

        return payloadItem;
    });

    return {
        payment_method: values.payment_method,
        customer_id: values.payment_method === 'credit' ? emptyStringToInteger(values.customer_id) : null,
        due_at: values.payment_method === 'credit' ? emptyStringToNull(values.due_at) : null,
        items,
    };
}

export function toPricingQuoteCheckoutPayload(
    values: PosSaleCreateFormData,
    quote: PricingQuote,
    productsById: Map<number, InventoryProduct>,
): PricingQuoteCheckoutPayload {
    if (values.items.length !== quote.items.length) {
        throw new Error('La cotizacion ya no coincide con las lineas actuales. Vuelve a cotizar antes de vender.');
    }

    return {
        quote_hash: quote.quote_hash,
        due_at: values.payment_method === 'credit' ? emptyStringToNull(values.due_at) : null,
        line_inputs: values.items.flatMap((item, index) => {
            const productId = Number.parseInt(item.product_id, 10);
            const quoteItem = quote.items[index];

            if (quoteItem === undefined || quoteItem.product_id !== productId) {
                throw new Error('La cotizacion no coincide con los productos actuales. Vuelve a cotizar antes de vender.');
            }

            const product = productsById.get(productId);
            const prescriptionCode = emptyStringToNull(item.prescription_code);
            const approvalCode = emptyStringToNull(item.approval_code);

            if (product?.is_controlled !== true) {
                return [];
            }

            return [{
                quote_item_id: quoteItem.id,
                prescription_code: prescriptionCode,
                approval_code: approvalCode,
            }];
        }),
    };
}

function emptyStringToNull(value: string): string | null {
    const normalized = value.trim();

    return normalized === '' ? null : normalized;
}

function emptyStringToInteger(value: string): number | null {
    const normalized = value.trim();

    return normalized === '' ? null : Number.parseInt(normalized, 10);
}

function isPositiveInteger(value: string): boolean {
    const parsed = Number(value);

    return Number.isInteger(parsed) && parsed > 0;
}
