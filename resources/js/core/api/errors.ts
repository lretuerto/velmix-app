import { isAxiosError } from 'axios';

export type ApiErrorKind =
    | 'authentication'
    | 'authorization'
    | 'conflict'
    | 'network'
    | 'not_found'
    | 'server'
    | 'timeout'
    | 'validation'
    | 'unknown';

export type ApiValidationErrors = Record<string, string[]>;

export class ApiError extends Error {
    public readonly status: number;
    public readonly requestId: string | null;
    public readonly payload: unknown;
    public readonly kind: ApiErrorKind;
    public readonly validationErrors: ApiValidationErrors;

    constructor(
        message: string,
        status = 500,
        requestId: string | null = null,
        payload: unknown = null,
        kind: ApiErrorKind = classifyApiError(status),
        validationErrors: ApiValidationErrors = {},
    ) {
        super(message);
        this.name = 'ApiError';
        this.status = status;
        this.requestId = requestId;
        this.payload = payload;
        this.kind = kind;
        this.validationErrors = validationErrors;
    }
}

export function toApiError(error: unknown): ApiError {
    if (error instanceof ApiError) {
        return error;
    }

    if (isAxiosError(error)) {
        const status = error.response?.status ?? 0;
        const requestId = headerToString(error.response?.headers, 'x-request-id');
        const payload = error.response?.data ?? null;
        const kind = classifyApiError(status, error.code);
        const validationErrors = extractValidationErrors(payload);
        const message = payloadMessage(payload) ?? fallbackAxiosMessage(kind, error.message);

        return new ApiError(message, status, requestId, payload, kind, validationErrors);
    }

    if (error instanceof Error) {
        return new ApiError(error.message, 500, null, null, 'unknown');
    }

    return new ApiError('Unexpected API error.', 500, null, null, 'unknown');
}

export function describeApiError(error: unknown): string {
    const apiError = error instanceof ApiError ? error : toApiError(error);
    const fields = Object.keys(apiError.validationErrors);
    const parts = [apiError.message];

    if (fields.length > 0) {
        parts.push(`Campos: ${fields.join(', ')}.`);
    }

    if (apiError.requestId !== null) {
        parts.push(`Request ID: ${apiError.requestId}.`);
    }

    return parts.join(' ');
}

export function isRetryableApiError(error: unknown): boolean {
    const apiError = error instanceof ApiError ? error : toApiError(error);

    return apiError.kind === 'network'
        || apiError.kind === 'timeout'
        || apiError.kind === 'server';
}

function payloadMessage(payload: unknown): string | null {
    if (typeof payload === 'string' && payload.trim() !== '') {
        return payload;
    }

    if (typeof payload === 'object' && payload !== null && 'message' in payload && typeof payload.message === 'string') {
        return payload.message;
    }

    return null;
}

function fallbackAxiosMessage(kind: ApiErrorKind, originalMessage: string): string {
    if (kind === 'timeout') {
        return 'La solicitud tardo demasiado en responder. Intenta nuevamente.';
    }

    if (kind === 'network') {
        return 'No pudimos conectar con el servidor. Revisa la conexion o intenta nuevamente.';
    }

    return originalMessage || 'Unexpected API error.';
}

function classifyApiError(status: number, code?: string): ApiErrorKind {
    if (code === 'ECONNABORTED' || code === 'ETIMEDOUT') {
        return 'timeout';
    }

    if (status === 0) {
        return 'network';
    }

    if (status === 401) {
        return 'authentication';
    }

    if (status === 403) {
        return 'authorization';
    }

    if (status === 404) {
        return 'not_found';
    }

    if (status === 409 || status === 412) {
        return 'conflict';
    }

    if (status === 422) {
        return 'validation';
    }

    if (status >= 500) {
        return 'server';
    }

    return 'unknown';
}

function extractValidationErrors(payload: unknown): ApiValidationErrors {
    if (typeof payload !== 'object' || payload === null || !('errors' in payload)) {
        return {};
    }

    const errors = payload.errors;

    if (typeof errors !== 'object' || errors === null || Array.isArray(errors)) {
        return {};
    }

    return Object.entries(errors).reduce<ApiValidationErrors>((carry, [field, messages]) => {
        if (Array.isArray(messages)) {
            carry[field] = messages.filter((message): message is string => typeof message === 'string');
        } else if (typeof messages === 'string') {
            carry[field] = [messages];
        }

        return carry;
    }, {});
}

function headerToString(headers: unknown, name: string): string | null {
    if (typeof headers === 'object' && headers !== null && 'get' in headers && typeof headers.get === 'function') {
        const value = headers.get(name) as unknown;

        return headerValueToString(value);
    }

    if (typeof headers !== 'object' || headers === null) {
        return null;
    }

    const lowerCaseName = name.toLowerCase();
    const entry = Object.entries(headers).find(([key]) => key.toLowerCase() === lowerCaseName);

    return headerValueToString(entry?.[1]);
}

function headerValueToString(header: unknown): string | null {
    if (Array.isArray(header)) {
        return header[0] ?? null;
    }

    return typeof header === 'string' ? header : null;
}
