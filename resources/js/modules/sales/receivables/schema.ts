import { z } from 'zod';
import type {
    SaleReceivableFollowUpPayload,
    SaleReceivablePaymentPayload,
} from '@/modules/sales/receivables/types';

export const receivablePaymentSchema = z.object({
    amount: z
        .string()
        .trim()
        .refine((value) => isPositiveNumber(value), {
            message: 'El monto debe ser un numero valido mayor a 0.',
        }),
    payment_method: z.enum(['cash', 'card', 'transfer', 'bank_transfer']),
    reference: z
        .string()
        .trim()
        .min(1, 'La referencia es obligatoria.')
        .max(80, 'La referencia no puede exceder 80 caracteres.'),
});

export const receivableFollowUpSchema = z.object({
    type: z.enum(['note', 'promise']),
    note: z
        .string()
        .trim()
        .min(1, 'La nota es obligatoria.')
        .max(300, 'La nota no puede exceder 300 caracteres.'),
    promised_amount: z
        .string()
        .trim()
        .refine((value) => value === '' || isPositiveNumber(value), {
            message: 'El monto prometido debe ser un numero valido mayor a 0.',
        }),
    promised_at: z.string().trim(),
}).superRefine((values, context) => {
    if (values.type === 'promise' && values.promised_at === '') {
        context.addIssue({
            code: z.ZodIssueCode.custom,
            path: ['promised_at'],
            message: 'La promesa requiere fecha comprometida.',
        });
    }
});

export type ReceivablePaymentFormData = z.infer<typeof receivablePaymentSchema>;
export type ReceivableFollowUpFormData = z.infer<typeof receivableFollowUpSchema>;

export const defaultReceivablePaymentValues: ReceivablePaymentFormData = {
    amount: '',
    payment_method: 'cash',
    reference: '',
};

export const defaultReceivableFollowUpValues: ReceivableFollowUpFormData = {
    type: 'note',
    note: '',
    promised_amount: '',
    promised_at: '',
};

export function toReceivablePaymentPayload(values: ReceivablePaymentFormData): SaleReceivablePaymentPayload {
    return {
        amount: Number(values.amount.trim()),
        payment_method: values.payment_method,
        reference: values.reference.trim(),
    };
}

export function toReceivableFollowUpPayload(values: ReceivableFollowUpFormData): SaleReceivableFollowUpPayload {
    return {
        type: values.type,
        note: values.note.trim(),
        promised_amount: values.promised_amount.trim() === '' ? null : Number(values.promised_amount.trim()),
        promised_at: values.promised_at.trim() === '' ? null : values.promised_at.trim(),
    };
}

function isPositiveNumber(value: string): boolean {
    const parsed = Number(value);

    return Number.isFinite(parsed) && parsed > 0;
}
