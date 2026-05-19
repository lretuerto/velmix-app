import { render, screen } from '@testing-library/react';
import { describe, expect, it, vi } from 'vitest';
import { ApiError } from '@/core/api/errors';
import { ApiErrorPanel } from '@/core/ui/feedback/ApiErrorPanel';

describe('ApiErrorPanel', () => {
    it('renders validation context with affected fields and retry action', () => {
        const onRetry = vi.fn();

        render(
            <ApiErrorPanel
                error={new ApiError(
                    'The given data was invalid.',
                    422,
                    'req-validation-001',
                    null,
                    'validation',
                    {
                        customer_id: ['Required'],
                        items: ['Required'],
                    },
                )}
                onRetry={onRetry}
            />,
        );

        expect(screen.getByText('Validacion requerida')).toBeInTheDocument();
        expect(screen.getByText(/Campos afectados: customer_id, items/i)).toBeInTheDocument();
        expect(screen.getByText(/Request ID: req-validation-001/i)).toBeInTheDocument();

        screen.getByRole('button', { name: 'Reintentar' }).click();

        expect(onRetry).toHaveBeenCalledTimes(1);
    });

    it('uses operational titles for retryable server failures', () => {
        render(
            <ApiErrorPanel
                error={new ApiError('Database unavailable.', 503, 'req-server-001')}
                retryLabel="Refrescar modulo"
                onRetry={vi.fn()}
            />,
        );

        expect(screen.getByText('Error operacional del servidor')).toBeInTheDocument();
        expect(screen.getByText(/Database unavailable/i)).toBeInTheDocument();
        expect(screen.getByRole('button', { name: 'Refrescar modulo' })).toBeInTheDocument();
    });
});
