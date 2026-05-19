import { describe, expect, it } from 'vitest';
import {
    cashSessionCloseSchema,
    cashSessionOpenSchema,
    cashMovementCreateSchema,
    toCashSessionClosePayload,
    toCashSessionOpenPayload,
    toCashMovementCreatePayload,
} from '@/modules/cash/sessions/schema';

describe('cash session schemas', () => {
    it('maps opening and movement payloads correctly', () => {
        const openParsed = cashSessionOpenSchema.parse({
            opening_amount: '100.50',
        });

        const movementParsed = cashMovementCreateSchema.parse({
            type: 'manual_in',
            amount: '15',
            reference: ' ING-001 ',
            notes: ' Fondo adicional ',
        });

        expect(toCashSessionOpenPayload(openParsed)).toEqual({
            opening_amount: 100.5,
        });

        expect(toCashMovementCreatePayload(movementParsed)).toEqual({
            type: 'manual_in',
            amount: 15,
            reference: 'ING-001',
            notes: 'Fondo adicional',
        });
    });

    it('maps close payload with denominations', () => {
        const parsed = cashSessionCloseSchema.parse({
            counted_amount: '130',
            denominations: [
                { value: '50', quantity: '2' },
                { value: '20', quantity: '1' },
            ],
        });

        expect(toCashSessionClosePayload(parsed)).toEqual({
            counted_amount: 130,
            denominations: [
                { value: 50, quantity: 2 },
                { value: 20, quantity: 1 },
            ],
        });
    });

    it('requires counted amount or denominations', () => {
        const result = cashSessionCloseSchema.safeParse({
            counted_amount: '',
            denominations: [],
        });

        expect(result.success).toBe(false);
    });
});
