import type { AppBoot } from '@/core/app/boot';
import { postJson } from '@/core/api/client';

export interface SessionLoginPayload {
    email: string;
    password: string;
    tenant?: string;
}

export interface SessionLogoutResponse {
    status: 'logged_out';
}

export function loginSession(payload: SessionLoginPayload): Promise<AppBoot> {
    return postJson<AppBoot, SessionLoginPayload>('/auth/session/login', payload);
}

export function logoutSession(): Promise<SessionLogoutResponse> {
    return postJson<SessionLogoutResponse, Record<string, never>>('/auth/session/logout', {});
}
