import { z } from 'zod';
import type {
    SalesCustomerCreatePayload,
    SalesCustomerUpdatePayload,
} from '@/modules/sales/customers/types';

export const customerFormSchema = z.object({
    document_type: z
        .string()
        .trim()
        .min(1, 'El tipo de documento es obligatorio.')
        .max(40, 'El tipo de documento no puede exceder 40 caracteres.'),
    document_number: z
        .string()
        .trim()
        .min(1, 'El numero de documento es obligatorio.')
        .max(40, 'El numero de documento no puede exceder 40 caracteres.'),
    name: z
        .string()
        .trim()
        .min(1, 'El nombre del cliente es obligatorio.')
        .max(160, 'El nombre no puede exceder 160 caracteres.'),
    phone: z
        .string()
        .trim()
        .max(40, 'El telefono no puede exceder 40 caracteres.'),
    email: z
        .string()
        .trim()
        .max(120, 'El correo no puede exceder 120 caracteres.')
        .refine((value) => value === '' || z.string().email().safeParse(value).success, {
            message: 'Ingresa un correo valido o deja el campo vacio.',
        }),
    credit_limit: z
        .string()
        .trim()
        .refine((value) => value === '' || isNonNegativeNumber(value), {
            message: 'El limite de credito debe ser un numero valido mayor o igual a 0.',
        }),
    credit_days: z
        .string()
        .trim()
        .refine((value) => value === '' || isNonNegativeInteger(value), {
            message: 'Los dias de credito deben ser un entero valido mayor o igual a 0.',
        }),
    block_on_overdue: z.boolean(),
    status: z.enum(['active', 'inactive']),
});

export type CustomerFormData = z.infer<typeof customerFormSchema>;

export const defaultCustomerFormValues: CustomerFormData = {
    document_type: 'dni',
    document_number: '',
    name: '',
    phone: '',
    email: '',
    credit_limit: '',
    credit_days: '',
    block_on_overdue: true,
    status: 'active',
};

export function customerToFormValues(customer: {
    document_type: string;
    document_number: string;
    name: string;
    phone: string | null;
    email: string | null;
    credit_limit: number | null;
    credit_days: number | null;
    block_on_overdue: boolean;
    status: string;
}): CustomerFormData {
    return {
        document_type: customer.document_type,
        document_number: customer.document_number,
        name: customer.name,
        phone: customer.phone ?? '',
        email: customer.email ?? '',
        credit_limit: customer.credit_limit !== null ? String(customer.credit_limit) : '',
        credit_days: customer.credit_days !== null ? String(customer.credit_days) : '',
        block_on_overdue: customer.block_on_overdue,
        status: customer.status === 'inactive' ? 'inactive' : 'active',
    };
}

export function toCustomerCreatePayload(values: CustomerFormData): SalesCustomerCreatePayload {
    return {
        document_type: values.document_type.trim(),
        document_number: values.document_number.trim(),
        name: values.name.trim(),
        phone: emptyStringToNull(values.phone),
        email: emptyStringToNull(values.email),
        credit_limit: emptyStringToNumber(values.credit_limit),
        credit_days: emptyStringToInteger(values.credit_days),
        block_on_overdue: values.block_on_overdue,
    };
}

export function toCustomerUpdatePayload(values: CustomerFormData): SalesCustomerUpdatePayload {
    return {
        ...toCustomerCreatePayload(values),
        status: values.status,
    };
}

function emptyStringToNull(value: string): string | null {
    const normalized = value.trim();

    return normalized === '' ? null : normalized;
}

function emptyStringToNumber(value: string): number | null {
    const normalized = value.trim();

    return normalized === '' ? null : Number(normalized);
}

function emptyStringToInteger(value: string): number | null {
    const normalized = value.trim();

    return normalized === '' ? null : Number.parseInt(normalized, 10);
}

function isNonNegativeNumber(value: string): boolean {
    const parsed = Number(value);

    return Number.isFinite(parsed) && parsed >= 0;
}

function isNonNegativeInteger(value: string): boolean {
    const parsed = Number(value);

    return Number.isInteger(parsed) && parsed >= 0;
}
