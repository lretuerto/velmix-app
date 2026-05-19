import type { ReactNode } from 'react';
import { toApiError, type ApiErrorKind } from '@/core/api/errors';
import { StatePanel } from '@/core/ui/feedback/StatePanel';

interface ApiErrorPanelProps {
    error: unknown;
    title?: string;
    onRetry?: () => void;
    retryLabel?: string;
    actions?: ReactNode;
}

const titleByKind: Record<ApiErrorKind, string> = {
    authentication: 'Sesion requerida',
    authorization: 'Permiso insuficiente',
    conflict: 'Estado desactualizado',
    network: 'Servidor no disponible',
    not_found: 'Registro no encontrado',
    server: 'Error operacional del servidor',
    timeout: 'Tiempo de espera agotado',
    validation: 'Validacion requerida',
    unknown: 'Error inesperado',
};

export function ApiErrorPanel({ error, title, onRetry, retryLabel = 'Reintentar', actions }: ApiErrorPanelProps) {
    const apiError = toApiError(error);
    const validationFields = Object.keys(apiError.validationErrors);
    const descriptionParts = [apiError.message];
    const hasActions = onRetry !== undefined || actions !== undefined;

    if (validationFields.length > 0) {
        descriptionParts.push(`Campos afectados: ${validationFields.join(', ')}.`);
    }

    if (apiError.requestId !== null) {
        descriptionParts.push(`Request ID: ${apiError.requestId}.`);
    }

    return (
        <StatePanel
            tone={apiError.kind === 'validation' || apiError.kind === 'conflict' ? 'warning' : 'danger'}
            title={title ?? titleByKind[apiError.kind]}
            description={descriptionParts.join(' ')}
            actions={hasActions ? (
                <div className="flex flex-wrap gap-3">
                    {onRetry !== undefined && (
                        <button
                            type="button"
                            className="rounded-[var(--velmix-radius-md)] bg-[var(--velmix-brand)] px-4 py-2 text-sm font-semibold text-white transition hover:opacity-90"
                            onClick={onRetry}
                        >
                            {retryLabel}
                        </button>
                    )}
                    {actions}
                </div>
            ) : undefined}
        />
    );
}
