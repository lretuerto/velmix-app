import { useAppShell } from '@/core/app/hooks';
import { StatePanel } from '@/core/ui/feedback/StatePanel';
import { InfoCard } from '@/shared/components/InfoCard';
import { PageHeader } from '@/shared/components/PageHeader';

export function WorkspaceHomePage() {
    const boot = useAppShell();

    return (
        <div className="space-y-6">
            <PageHeader
                eyebrow="Workspace"
                title="Frontend profesional en operacion"
                description="La base ya quedo estable y Sprint 1 esta vivo: shell, boot de sesion, tenant switcher, guards por permisos, cliente HTTP tipado y modulos operativos para seguir construyendo sin improvisacion."
            />

            {boot.tenant.selection_error !== null && (
                <StatePanel tone="warning" title="Tenant no resuelto" description={boot.tenant.selection_error} />
            )}

            <section className="grid gap-4 xl:grid-cols-4 md:grid-cols-2">
                <InfoCard label="Frontend stage" value={boot.app.frontend_stage} help="Huella visible del milestone actual." />
                <InfoCard
                    label="Auth mode"
                    value={boot.auth.mode}
                    help="En navegador trabajamos session-first; bearer queda para integraciones."
                />
                <InfoCard
                    label="Tenant activo"
                    value={boot.tenant.selected?.code ?? 'none'}
                    help="El cliente API inyecta X-Tenant-Id cuando el tenant esta seleccionado."
                />
                <InfoCard
                    label="Request ID"
                    value={boot.app.request_id}
                    help="Correlacion operativa visible desde el shell para troubleshooting."
                />
            </section>

            {!boot.auth.authenticated && (
                <StatePanel
                    tone="neutral"
                    title="Shell listo, autenticacion web pendiente"
                    description="La SPA ya puede montarse y navegarse, pero el repositorio todavia no tiene una experiencia de login web dedicada. En cuanto exista una sesion Laravel valida, el shell levantara usuario, memberships, roles y permisos automaticamente."
                />
            )}

            {boot.auth.authenticated && boot.tenant.selected === null && boot.tenant.memberships.length > 1 && (
                <StatePanel
                    tone="neutral"
                    title="Selecciona un tenant para empezar"
                    description="El usuario pertenece a multiples tenants. El selector superior recarga el shell con el tenant correcto para que los modulos consuman la API con contexto seguro."
                />
            )}

            <section className="grid gap-4 lg:grid-cols-[minmax(0,1.5fr)_minmax(0,1fr)]">
                <article className="rounded-[var(--velmix-radius-xl)] border border-[var(--velmix-border)] bg-[var(--velmix-panel)] p-6 shadow-[var(--velmix-shadow)]">
                    <p className="text-xs font-semibold uppercase tracking-[0.18em] text-[var(--velmix-brand)]">
                        Alcance implementado
                    </p>
                    <ul className="mt-4 space-y-3 text-sm leading-6 text-[var(--velmix-muted)]">
                        <li>Shell React + TypeScript servido por Laravel en `/app`.</li>
                        <li>Bootstrap server-side con usuario, memberships, tenant activo, roles y permisos.</li>
                        <li>Cliente Axios centralizado con `X-Tenant-Id` listo para los modulos.</li>
                        <li>Permission boundaries reutilizables para rutas funcionales.</li>
                        <li>Rutas modulares operativas para platform, productos, clientes, cobranzas, POS y caja.</li>
                    </ul>
                </article>

                <article className="rounded-[var(--velmix-radius-xl)] border border-[var(--velmix-border)] bg-[var(--velmix-panel)] p-6 shadow-[var(--velmix-shadow)]">
                    <p className="text-xs font-semibold uppercase tracking-[0.18em] text-[var(--velmix-brand)]">
                        Entrega siguiente
                    </p>
                    <ul className="mt-4 space-y-3 text-sm leading-6 text-[var(--velmix-muted)]">
                        <li>Platform dashboard real con `/reports/platform-observability`.</li>
                        <li>Listado y alta de productos sobre `/inventory/products`.</li>
                        <li>Listado y edicion de clientes sobre `/sales/customers`.</li>
                        <li>Receivables, pagos y follow-ups sobre `/sales/receivables`.</li>
                        <li>Ventas POS reales sobre `/pos/sales`.</li>
                        <li>Sesiones de caja reales sobre `/cash/sessions`.</li>
                    </ul>
                </article>
            </section>
        </div>
    );
}
