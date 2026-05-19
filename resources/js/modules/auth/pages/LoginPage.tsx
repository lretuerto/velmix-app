import { FormEvent, useState, useTransition } from 'react';
import { Link, useSearchParams } from 'react-router-dom';
import { loginSession } from '@/core/auth/api/session';
import { useAppShell } from '@/core/app/hooks';
import { describeApiError } from '@/core/api/errors';
import { StatePanel } from '@/core/ui/feedback/StatePanel';
import { buildPostLoginUrl, safeRedirectPath } from '@/modules/auth/sessionRedirect';

export function LoginPage() {
    const boot = useAppShell();
    const [searchParams] = useSearchParams();
    const [email, setEmail] = useState('');
    const [password, setPassword] = useState('');
    const [tenant, setTenant] = useState(searchParams.get('tenant') ?? '');
    const [error, setError] = useState<string | null>(null);
    const [isPending, startTransition] = useTransition();

    const redirectPath = safeRedirectPath(searchParams.get('redirect'));

    if (boot.auth.authenticated) {
        return (
            <div className="mx-auto flex min-h-screen max-w-3xl items-center justify-center px-6">
                <StatePanel
                    tone="success"
                    title="Sesion activa"
                    description={`Ya ingresaste como ${boot.auth.user?.email ?? 'usuario autenticado'}. Puedes volver al workspace o cambiar de tenant desde el selector lateral.`}
                    actions={(
                        <Link
                            to={{ pathname: redirectPath, search: window.location.search }}
                            className="inline-flex rounded-[var(--velmix-radius-md)] bg-[var(--velmix-brand)] px-4 py-2 text-sm font-semibold text-white transition hover:opacity-90"
                        >
                            Volver al workspace
                        </Link>
                    )}
                />
            </div>
        );
    }

    const submit = (event: FormEvent<HTMLFormElement>) => {
        event.preventDefault();
        setError(null);

        startTransition(() => {
            void loginSession({
                email,
                password,
                tenant: tenant.trim() !== '' ? tenant.trim() : undefined,
            })
                .then((nextBoot) => {
                    window.location.assign(buildPostLoginUrl(
                        redirectPath,
                        nextBoot.tenant.selected?.code ?? tenant,
                    ));
                })
                .catch((requestError: unknown) => {
                    setError(describeApiError(requestError));
                });
        });
    };

    return (
        <main className="mx-auto grid min-h-screen max-w-6xl grid-cols-1 items-center gap-8 px-5 py-8 lg:grid-cols-[1.1fr_0.9fr]">
            <section className="velmix-card p-8">
                <p className="velmix-kicker">
                    Acceso UAT seguro
                </p>
                <h1 className="mt-4 text-5xl font-black leading-[0.98] tracking-[-0.07em] text-[var(--velmix-ink)]">
                    Entra al workspace operativo de VELMiX
                </h1>
                <p className="mt-4 max-w-2xl text-sm leading-6 text-[var(--velmix-muted)]">
                    El frontend trabaja con sesion Laravel, RBAC y tenant activo. Este login desbloquea el recorrido visual POS quote-first, caja, cartera, catalogo y clientes sin usar tokens manuales en el navegador.
                </p>

                <div className="mt-6 grid gap-3 text-sm md:grid-cols-3">
                    <InfoPill label="Contrato" value="Session-first" />
                    <InfoPill label="Tenant smoke" value="botica-central" />
                    <InfoPill label="UAT" value="Quote-first POS" />
                </div>
            </section>

            <section className="velmix-card p-6">
                <form className="space-y-5" onSubmit={submit}>
                    <div>
                        <p className="velmix-kicker text-[var(--velmix-muted)]">
                            Credenciales
                        </p>
                        <h2 className="mt-2 text-3xl font-black tracking-[-0.05em]">Iniciar sesion</h2>
                    </div>

                    <label className="block text-sm font-semibold text-[var(--velmix-ink)]">
                        Email
                        <input
                            className="velmix-input mt-2 px-3 py-3 text-sm"
                            type="text"
                            inputMode="email"
                            autoComplete="username"
                            value={email}
                            onChange={(event) => setEmail(event.target.value)}
                            required
                        />
                    </label>

                    <label className="block text-sm font-semibold text-[var(--velmix-ink)]">
                        Password
                        <input
                            className="velmix-input mt-2 px-3 py-3 text-sm"
                            type="password"
                            autoComplete="current-password"
                            value={password}
                            onChange={(event) => setPassword(event.target.value)}
                            required
                        />
                    </label>

                    <label className="block text-sm font-semibold text-[var(--velmix-ink)]">
                        Tenant
                        <input
                            className="velmix-input mt-2 px-3 py-3 text-sm"
                            type="text"
                            value={tenant}
                            onChange={(event) => setTenant(event.target.value)}
                            placeholder="botica-central"
                        />
                    </label>

                    {error !== null && (
                        <div className="rounded-[var(--velmix-radius-md)] border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-900">
                            {error}
                        </div>
                    )}

                    <button
                        type="submit"
                        disabled={isPending}
                        className="velmix-button-primary w-full px-4 py-3 text-sm disabled:cursor-not-allowed disabled:opacity-60"
                    >
                        {isPending ? 'Validando sesion...' : 'Iniciar sesion'}
                    </button>
                </form>
            </section>
        </main>
    );
}

interface InfoPillProps {
    label: string;
    value: string;
}

function InfoPill({ label, value }: InfoPillProps) {
    return (
        <div className="velmix-card-strong p-3">
            <p className="text-[10px] font-black uppercase tracking-[0.16em] text-[var(--velmix-muted)]">{label}</p>
            <p className="mt-1 font-black">{value}</p>
        </div>
    );
}
