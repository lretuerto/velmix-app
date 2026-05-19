import { describe, expect, it } from 'vitest';
import {
    customerFormSchema,
    toCustomerCreatePayload,
    toCustomerUpdatePayload,
} from '@/modules/sales/customers/schema';

describe('customerFormSchema', () => {
    it('normalizes optional fields into backend payload shape', () => {
        const parsed = customerFormSchema.parse({
            document_type: ' dni ',
            document_number: ' 12345678 ',
            name: ' Cliente Mostrador ',
            phone: ' ',
            email: ' ',
            credit_limit: '150.50',
            credit_days: '15',
            block_on_overdue: true,
            status: 'active',
        });

        expect(toCustomerCreatePayload(parsed)).toEqual({
            document_type: 'dni',
            document_number: '12345678',
            name: 'Cliente Mostrador',
            phone: null,
            email: null,
            credit_limit: 150.5,
            credit_days: 15,
            block_on_overdue: true,
        });

        expect(toCustomerUpdatePayload(parsed)).toEqual({
            document_type: 'dni',
            document_number: '12345678',
            name: 'Cliente Mostrador',
            phone: null,
            email: null,
            credit_limit: 150.5,
            credit_days: 15,
            block_on_overdue: true,
            status: 'active',
        });
    });

    it('rejects invalid email and negative credit values', () => {
        const result = customerFormSchema.safeParse({
            document_type: 'dni',
            document_number: '12345678',
            name: 'Cliente',
            phone: '',
            email: 'correo-invalido',
            credit_limit: '-1',
            credit_days: '3.5',
            block_on_overdue: false,
            status: 'active',
        });

        expect(result.success).toBe(false);
    });
});
