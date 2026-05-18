import axios, { type AxiosRequestConfig, type InternalAxiosRequestConfig } from 'axios';
import { readAppBoot } from '@/core/app/boot';
import type { DataEnvelope } from '@/core/api/contracts';
import { toApiError } from '@/core/api/errors';

const boot = readAppBoot();

export const API_TIMEOUT_MS = 15_000;

export const apiClient = axios.create({
    timeout: API_TIMEOUT_MS,
    withCredentials: true,
    headers: {
        Accept: 'application/json',
        'X-Requested-With': 'XMLHttpRequest',
    },
});

apiClient.interceptors.request.use((config: InternalAxiosRequestConfig) => {
    if (boot.tenant.selected !== null) {
        config.headers['X-Tenant-Id'] = String(boot.tenant.selected.id);
    }

    const csrfToken = document.querySelector<HTMLMetaElement>('meta[name="csrf-token"]')?.content;

    if (csrfToken !== undefined && csrfToken !== '') {
        config.headers['X-CSRF-TOKEN'] = csrfToken;
    }

    return config;
});

apiClient.interceptors.response.use(
    (response) => response,
    (error) => Promise.reject(toApiError(error)),
);

export async function getJson<TData>(url: string, config?: AxiosRequestConfig): Promise<TData> {
    const response = await apiClient.get<DataEnvelope<TData>>(url, config);

    return response.data.data;
}

export async function postJson<TData, TPayload = Record<string, unknown>>(
    url: string,
    payload?: TPayload,
    config?: AxiosRequestConfig,
): Promise<TData> {
    const response = await apiClient.post<DataEnvelope<TData>>(url, payload, config);

    return response.data.data;
}

export async function patchJson<TData, TPayload = Record<string, unknown>>(
    url: string,
    payload?: TPayload,
    config?: AxiosRequestConfig,
): Promise<TData> {
    const response = await apiClient.patch<DataEnvelope<TData>>(url, payload, config);

    return response.data.data;
}

export function createIdempotencyKey(scope: string): string {
    const uuid =
        typeof globalThis.crypto?.randomUUID === 'function'
            ? globalThis.crypto.randomUUID()
            : `${Date.now()}-${Math.round(Math.random() * 1_000_000)}`;

    return `${scope}-${uuid}`;
}
