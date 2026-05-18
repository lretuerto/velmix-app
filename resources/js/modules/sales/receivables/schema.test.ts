import { describe, expect, it } from 'vitest';
import {
    receivableFollowUpSchema,
    receivablePaymentSchema,
    toReceivableFollowUpPayload,
    toReceivablePaymentPayload,
} from '@/modules/sales/receivables/schema';

describe('receivable schemas', () => {
    it('maps payment fields into backend payload shape', () => {
        const parsed = receivablePaymentSchema.parse({
            amount: '8.50',
            payment_method: 'cash',
            reference: ' COBRO-001 ',
        });

        expect(toReceivablePaymentPayload(parsed)).toEqual({
            amount: 8.5,
            payment_method: 'cash',
            reference: 'COBRO-001',
        });
    });

    it('requires promised_at when follow up type is promise', () => {
        const result = receivableFollowUpSchema.safeParse({
            type: 'promise',
            note: 'Cliente promete cancelar el viernes',
            promised_amount: '9',
            promised_at: '',
        });

        expect(result.success).toBe(false);
    });

    it('maps follow up payload with nullable promise values', () => {
        const parsed = receivableFollowUpSchema.parse({
            type: 'note',
            note: 'Se llamo al cliente',
            promised_amount: '',
            promised_at: '',
        });

        expect(toReceivableFollowUpPayload(parsed)).toEqual({
            type: 'note',
            note: 'Se llamo al cliente',
            promised_amount: null,
            promised_at: null,
        });
    });
});
