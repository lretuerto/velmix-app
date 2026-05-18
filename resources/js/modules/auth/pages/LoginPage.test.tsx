import { afterEach, describe, expect, it, vi } from 'vitest';
import { fireEvent, render, screen, waitFor } from '@testing-library/react';
import { MemoryRouter } from 'react-router-dom';
import { AppProviders } from '@/core/app/providers';
import type { AppBoot } from '@/core/app/boot';
import { ApiError } from '@/core/api/errors';
import { loginSession } from '@/core/auth/api/session';
import { LoginPage } from '@/modules/auth/pages/LoginPage';
import { buildPostLoginUrl, safeRedirectPath } from '@/modules/auth/sessionRedirect';
import { queryClient } from '@/core/query/client';

vi.mock('@/core/auth/api/session', () => ({
    loginSession: vi.fn(),
}));

const loginSessionMock = vi.mocked(loginSession);

describe('LoginPage', () => {
    afterEach(() => {
        queryClient.clear();
        vi.clearAllMocks();
        delete window.__VELMIX_BOOT__;
    });

    it('submits credentials to the session login endpoint', async () => {
        loginSessionMock.mockReturnValue(new Promise<AppBoot>(() => {}));
        window.__VELMIX_BOOT__ = makeGuestBoot();

        renderPage('/login?tenant=botica-central&redirect=/pos/sales');

        fireEvent.change(screen.getByLabelText('Email'), { target: { value: 'pos-smoke@velmix.test' } });
        fireEvent.change(screen.getByLabelText('Password'), { target: { value: 'pos-smoke-local-only' } });
        fireEvent.click(screen.getByRole('button', { name: 'Iniciar sesion' }));

        await waitFor(() => {
            expect(loginSessionMock).toHaveBeenCalledWith({
                email: 'pos-smoke@velmix.test',
                password: 'pos-smoke-local-only',
                tenant: 'botica-central',
            });
        });
    });

    it('renders validation errors without navigating away', async () => {
        loginSessionMock.mockRejectedValue(new ApiError('Las credenciales no son validas.', 422, 'req-login-001'));
        window.__VELMIX_BOOT__ = makeGuestBoot();

        renderPage('/login?tenant=botica-central');

        fireEvent.change(screen.getByLabelText('Email'), { target: { value: 'pos-smoke@velmix.test' } });
        fireEvent.change(screen.getByLabelText('Password'), { target: { value: 'wrong-password' } });
        fireEvent.click(screen.getByRole('button', { name: 'Iniciar sesion' }));

        expect(await screen.findByText(/Las credenciales no son validas/i)).toBeInTheDocument();
        expect(screen.getByText(/Request ID: req-login-001/i)).toBeInTheDocument();
    });

    it('sanitizes post-login redirects', () => {
        expect(safeRedirectPath('/pos/sales')).toBe('/pos/sales');
        expect(safeRedirectPath('//evil.test')).toBe('/');
        expect(safeRedirectPath('/login')).toBe('/');
        expect(buildPostLoginUrl('/pos/sales', 'botica-central')).toBe('/app/pos/sales?tenant=botica-central');
    });
});

function renderPage(initialEntry: string) {
    render(
        <MemoryRouter initialEntries={[initialEntry]}>
            <AppProviders>
                <LoginPage />
            </AppProviders>
        </MemoryRouter>,
    );
}

function makeGuestBoot(): AppBoot {
    return {
        ...makeBoot(),
        auth: {
            authenticated: false,
            mode: 'guest',
            user: null,
        },
        tenant: {
            selected: null,
            memberships: [],
            selection_error: null,
        },
        rbac: {
            roles: [],
            permissions: [],
        },
    };
}

function makeBoot(): AppBoot {
    return {
        app: {
            name: 'VELMiX ERP',
            environment: 'testing',
            request_id: 'test-request',
            frontend_stage: 'test',
        },
        auth: {
            authenticated: true,
            mode: 'session',
            user: {
                id: 1,
                name: 'POS Smoke Operator',
                email: 'pos-smoke@velmix.test',
            },
        },
        tenant: {
            selected: {
                id: 10,
                code: 'botica-central',
                name: 'Botica Central',
                status: 'active',
            },
            memberships: [
                {
                    id: 10,
                    code: 'botica-central',
                    name: 'Botica Central',
                    status: 'active',
                },
            ],
            selection_error: null,
        },
        rbac: {
            roles: [],
            permissions: ['pos.sale.read'],
        },
        links: {
            health_live: '/health/live',
            health_ready: '/health/ready',
            auth_me: '/auth/me',
            tenant_ping: '/tenant/ping',
        },
    };
}
