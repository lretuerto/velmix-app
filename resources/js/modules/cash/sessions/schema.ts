import { z } from 'zod';
import type {
    CashMovementCreatePayload,
    CashSessionClosePayload,
    CashSessionOpenPayload,
} from '@/modules/cash/sessions/types';

export const cashSessionOpenSchema = z.object({
    opening_amount: z
        .string()
        .trim()
        .refine((value) => isNonNegativeNumber(value), {
            message: 'El monto de apertura debe ser un numero valido mayor o igual a 0.',
        }),
});

export const cashDenominationSchema = z.object({
    value: z
        .string()
        .trim()
        .refine((value) => value === '' || isPositiveNumber(value), {
            message: 'El valor debe ser un numero valido mayor a 0.',
        }),
    quantity: z
        .string()
        .trim()
        .refine((value) => value === '' || isPositiveInteger(value), {
            message: 'La cantidad debe ser un entero mayor a 0.',
        }),
});

export const cashSessionCloseSchema = z.object({
    counted_amount: z
        .string()
        .trim()
        .refine((value) => value === '' || isNonNegativeNumber(value), {
            message: 'El monto contado debe ser un numero valido mayor o igual a 0.',
        }),
    denominations: z.array(cashDenominationSchema),
}).superRefine((values, context) => {
    const hasCountedAmount = values.counted_amount !== '';
    const hasDenominations = values.denominations.some((item) => item.value !== '' && item.quantity !== '');

    if (!hasCountedAmount && !hasDenominations) {
        context.addIssue({
            code: z.ZodIssueCode.custom,
            path: ['counted_amount'],
            message: 'Ingresa un monto contado o al menos una denominacion.',
        });
    }
});

export const cashMovementCreateSchema = z.object({
    type: z.enum(['manual_in', 'manual_out']),
    amount: z
        .string()
        .trim()
        .refine((value) => isPositiveNumber(value), {
            message: 'El monto debe ser un numero valido mayor a 0.',
        }),
    reference: z
        .string()
        .trim()
        .min(1, 'La referencia es obligatoria.')
        .max(80, 'La referencia no puede exceder 80 caracteres.'),
    notes: z
        .string()
        .trim()
        .max(200, 'Las notas no pueden exceder 200 caracteres.'),
});

export type CashSessionOpenFormData = z.infer<typeof cashSessionOpenSchema>;
export type CashSessionCloseFormData = z.infer<typeof cashSessionCloseSchema>;
export type CashMovementCreateFormData = z.infer<typeof cashMovementCreateSchema>;

export const defaultCashSessionOpenValues: CashSessionOpenFormData = {
    opening_amount: '',
};

export const defaultCashDenominationValues: CashSessionCloseFormData['denominations'][number] = {
    value: '',
    quantity: '',
};

export const defaultCashSessionCloseValues: CashSessionCloseFormData = {
    counted_amount: '',
    denominations: [],
};

export const defaultCashMovementCreateValues: CashMovementCreateFormData = {
    type: 'manual_in',
    amount: '',
    reference: '',
    notes: '',
};

export function toCashSessionOpenPayload(values: CashSessionOpenFormData): CashSessionOpenPayload {
    return {
        opening_amount: Number(values.opening_amount.trim()),
    };
}

export function toCashSessionClosePayload(values: CashSessionCloseFormData): CashSessionClosePayload {
    const denominations = values.denominations
        .filter((item) => item.value.trim() !== '' && item.quantity.trim() !== '')
        .map((item) => ({
            value: Number(item.value.trim()),
            quantity: Number.parseInt(item.quantity.trim(), 10),
        }));

    return {
        counted_amount: values.counted_amount.trim() === '' ? null : Number(values.counted_amount.trim()),
        denominations: denominations.length > 0 ? denominations : undefined,
    };
}

export function toCashMovementCreatePayload(values: CashMovementCreateFormData): CashMovementCreatePayload {
    return {
        type: values.type,
        amount: Number(values.amount.trim()),
        reference: values.reference.trim(),
        notes: values.notes.trim() === '' ? null : values.notes.trim(),
    };
}

function isPositiveNumber(value: string): boolean {
    const parsed = Number(value);

    return Number.isFinite(parsed) && parsed > 0;
}

function isNonNegativeNumber(value: string): boolean {
    const parsed = Number(value);

    return Number.isFinite(parsed) && parsed >= 0;
}

function isPositiveInteger(value: string): boolean {
    const parsed = Number(value);

    return Number.isInteger(parsed) && parsed > 0;
}
