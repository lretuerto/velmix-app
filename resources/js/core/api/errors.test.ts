import { AxiosHeaders } from 'axios';
import { describe, expect, it } from 'vitest';
import { ApiError, describeApiError, isRetryableApiError, toApiError } from '@/core/api/errors';

describe('API error normalization', () => {
    it('preserves Laravel validation details and request correlation', () => {
        const error = toApiError({
            isAxiosError: true,
            message: 'Request failed with status code 422',
            response: {
                status: 422,
                headers: new AxiosHeaders({ 'x-request-id': 'req-validation-001' }),
                data: {
                    message: 'The given data was invalid.',
                    errors: {
                        customer_id: ['El cliente es obligatorio.'],
                        items: ['Agrega al menos una linea.'],
                    },
                },
            },
        });

        expect(error.kind).toBe('validation');
        expect(error.status).toBe(422);
        expect(error.requestId).toBe('req-validation-001');
        expect(error.validationErrors).toEqual({
            customer_id: ['El cliente es obligatorio.'],
            items: ['Agrega al menos una linea.'],
        });
        expect(describeApiError(error)).toBe(
            'The given data was invalid. Campos: customer_id, items. Request ID: req-validation-001.',
        );
    });

    it('classifies conflict responses as non-retryable business drift', () => {
        const error = toApiError({
            isAxiosError: true,
            message: 'Request failed with status code 409',
            response: {
                status: 409,
                headers: { 'X-Request-Id': 'req-conflict-001' },
                data: {
                    message: 'La cotizacion expiro. Vuelve a cotizar.',
                },
            },
        });

        expect(error.kind).toBe('conflict');
        expect(error.requestId).toBe('req-conflict-001');
        expect(isRetryableApiError(error)).toBe(false);
    });

    it('classifies network failures as retryable without pretending they are server 500s', () => {
        const error = toApiError({
            isAxiosError: true,
            message: 'Network Error',
            request: {},
        });

        expect(error.kind).toBe('network');
        expect(error.status).toBe(0);
        expect(error.message).toBe('No pudimos conectar con el servidor. Revisa la conexion o intenta nuevamente.');
        expect(isRetryableApiError(error)).toBe(true);
    });

    it('classifies timeouts as retryable bounded latency failures', () => {
        const error = toApiError({
            isAxiosError: true,
            code: 'ECONNABORTED',
            message: 'timeout of 15000ms exceeded',
            request: {},
        });

        expect(error.kind).toBe('timeout');
        expect(error.message).toBe('La solicitud tardo demasiado en responder. Intenta nuevamente.');
        expect(isRetryableApiError(error)).toBe(true);
    });

    it('does not wrap ApiError instances twice', () => {
        const original = new ApiError('Original', 503, 'req-server-001');

        expect(toApiError(original)).toBe(original);
        expect(isRetryableApiError(original)).toBe(true);
    });
});
