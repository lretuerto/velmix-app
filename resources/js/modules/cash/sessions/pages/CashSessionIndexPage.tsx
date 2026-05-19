import { useEffect, useState } from 'react';
import { describeApiError } from '@/core/api/errors';
import { useAppShell } from '@/core/app/hooks';
import { hasPermission } from '@/core/auth/permissions';
import { ApiErrorPanel } from '@/core/ui/feedback/ApiErrorPanel';
import { StatePanel } from '@/core/ui/feedback/StatePanel';
import { useToast } from '@/core/ui/feedback/toast';
import { CashSessionDetailPanel } from '@/modules/cash/sessions/components/CashSessionDetailPanel';
import { CashSessionHistoryTable } from '@/modules/cash/sessions/components/CashSessionHistoryTable';
import { CloseCashSessionForm } from '@/modules/cash/sessions/components/CloseCashSessionForm';
import { ManualCashMovementForm } from '@/modules/cash/sessions/components/ManualCashMovementForm';
import { OpenCashSessionForm } from '@/modules/cash/sessions/components/OpenCashSessionForm';
import { useCashSessionDetail } from '@/modules/cash/sessions/hooks/useCashSessionDetail';
import { useCashSessionHistory } from '@/modules/cash/sessions/hooks/useCashSessionHistory';
import {
    useCloseCashSession,
    useCreateCashMovement,
    useOpenCashSession,
} from '@/modules/cash/sessions/hooks/useCashSessionMutations';
import { useCashSessionMovements } from '@/modules/cash/sessions/hooks/useCashSessionMovements';
import { useCurrentCashSession } from '@/modules/cash/sessions/hooks/useCurrentCashSession';
import {
    toCashMovementCreatePayload,
    toCashSessionClosePayload,
    toCashSessionOpenPayload,
    type CashMovementCreateFormData,
    type CashSessionCloseFormData,
    type CashSessionOpenFormData,
} from '@/modules/cash/sessions/schema';
import { PageHeader } from '@/shared/components/PageHeader';
import { formatCurrency, formatNumber } from '@/shared/utils/formatters';

export function CashSessionIndexPage() {
    const boot = useAppShell();
    const toast = useToast();
    const currentSessionQuery = useCurrentCashSession();
    const historyQuery = useCashSessionHistory();
    const openMutation = useOpenCashSession();
    const closeMutation = useCloseCashSession();
    const movementMutation = useCreateCashMovement();
    const currentSession = currentSessionQuery.data ?? null;
    const history = historyQuery.data ?? [];
    const [selectedSessionId, setSelectedSessionId] = useState<number | null>(null);
    const canOpenSession = hasPermission(boot.rbac.permissions, 'cash.session.open');
    const canCloseSession = hasPermission(boot.rbac.permissions, 'cash.session.close');
    const canCreateMovement = hasPermission(boot.rbac.permissions, 'cash.movement.create');
    const canReadMovements = hasPermission(boot.rbac.permissions, 'cash.movement.read');
    const detailQuery = useCashSessionDetail({ sessionId: selectedSessionId });
    const movementsQuery = useCashSessionMovements({
        sessionId: selectedSessionId,
        enabled: canReadMovements,
    });
    const selectedSession = (currentSession !== null && currentSession?.id === selectedSessionId
        ? currentSession
        : history.find((session) => session.id === selectedSessionId)) ?? currentSession ?? null;
    const isInitialLoading = currentSessionQuery.isLoading && historyQuery.isLoading && currentSession === null && history.length === 0;

    useEffect(() => {
        const availableHistory = historyQuery.data ?? [];

        if (selectedSessionId !== null) {
            return;
        }

        if (currentSession !== null) {
            setSelectedSessionId(currentSession.id);
            return;
        }

        if (availableHistory.length > 0) {
            setSelectedSessionId(availableHistory[0].id);
        }
    }, [currentSession, historyQuery.data, selectedSessionId]);

    const handleRefresh = async () => {
        await Promise.all([
            currentSessionQuery.refetch(),
            historyQuery.refetch(),
            selectedSessionId !== null ? detailQuery.refetch() : Promise.resolve(),
            selectedSessionId !== null && canReadMovements ? movementsQuery.refetch() : Promise.resolve(),
        ]);
    };

    const handleOpenSession = async (values: CashSessionOpenFormData) => {
        openMutation.reset();

        try {
            const result = await openMutation.mutateAsync(toCashSessionOpenPayload(values));

            setSelectedSessionId(result.id);
            toast.success({
                title: 'Caja abierta',
                description: `La caja #${result.id} se abrio con ${formatCurrency(result.opening_amount)}.`,
            });

            return true;
        } catch (error) {
            toast.danger({
                title: 'No pudimos abrir la caja',
                description: describeApiError(error),
            });

            return false;
        }
    };

    const handleCloseSession = async (values: CashSessionCloseFormData) => {
        closeMutation.reset();

        try {
            const result = await closeMutation.mutateAsync(toCashSessionClosePayload(values));

            setSelectedSessionId(result.id);
            toast.success({
                title: 'Caja cerrada',
                description: `La caja #${result.id} cerro con discrepancia ${formatCurrency(result.discrepancy_amount ?? 0)}.`,
            });

            return true;
        } catch (error) {
            toast.danger({
                title: 'No pudimos cerrar la caja',
                description: describeApiError(error),
            });

            return false;
        }
    };

    const handleCreateMovement = async (values: CashMovementCreateFormData) => {
        movementMutation.reset();

        try {
            const result = await movementMutation.mutateAsync(toCashMovementCreatePayload(values));

            toast.success({
                title: 'Movimiento registrado',
                description: `Se registro ${result.type} por ${formatCurrency(result.amount)} en la caja actual.`,
            });

            return true;
        } catch (error) {
            toast.danger({
                title: 'No pudimos registrar el movimiento',
                description: describeApiError(error),
            });

            return false;
        }
    };

    return (
        <div className="space-y-6">
            <PageHeader
                eyebrow="Cash"
                title="Sesiones de caja"
                description="El modulo ya opera apertura, lectura, cierre y movimientos manuales sobre la caja real del tenant, con lectura de profitability y expected amount."
                actions={
                    <button
                        type="button"
                        className="rounded-[var(--velmix-radius-md)] border border-[var(--velmix-border-strong)] bg-[var(--velmix-panel-strong)] px-4 py-2 text-sm font-semibold transition hover:border-[var(--velmix-brand)] disabled:cursor-not-allowed disabled:opacity-60"
                        onClick={() => {
                            void handleRefresh();
                        }}
                        disabled={
                            currentSessionQuery.isFetching
                            || historyQuery.isFetching
                            || detailQuery.isFetching
                            || movementsQuery.isFetching
                        }
                    >
                        {currentSessionQuery.isFetching || historyQuery.isFetching || detailQuery.isFetching || movementsQuery.isFetching
                            ? 'Refrescando...'
                            : 'Refrescar caja'}
                    </button>
                }
            />

            <section className="grid gap-4 xl:grid-cols-5 md:grid-cols-2">
                <MetricCard
                    label="Caja actual"
                    value={currentSession !== null ? `#${currentSession.id}` : 'Sin caja'}
                    help="Caja abierta del tenant actual, si existe."
                />
                <MetricCard
                    label="Expected"
                    value={currentSession !== null ? formatCurrency(currentSession.expected_amount) : 'N/A'}
                    help="Monto esperado segun ventas cash y movimientos."
                />
                <MetricCard
                    label="Sales total"
                    value={currentSession !== null ? formatCurrency(currentSession.sales_total) : 'N/A'}
                    help="Ventas completadas desde la apertura."
                />
                <MetricCard
                    label="Receivable cash"
                    value={currentSession !== null ? formatCurrency(currentSession.receivable_cash_total) : 'N/A'}
                    help="Cobranza cash acumulada en la caja actual."
                />
                <MetricCard
                    label="Historial"
                    value={formatNumber(history.length)}
                    help="Sesiones de caja registradas para el tenant."
                />
            </section>

            {isInitialLoading && (
                <StatePanel
                    tone="neutral"
                    title="Cargando caja operativa"
                    description="Estamos sincronizando la caja actual, historial y permisos antes de habilitar acciones de apertura, movimientos o cierre."
                />
            )}

            {(currentSessionQuery.isError || historyQuery.isError) && (
                <ApiErrorPanel
                    title="No pudimos cargar el cockpit de caja"
                    error={currentSessionQuery.isError ? currentSessionQuery.error : historyQuery.error}
                    retryLabel="Refrescar caja"
                    onRetry={() => {
                        void handleRefresh();
                    }}
                />
            )}

            <section className="grid gap-6 xl:grid-cols-[minmax(0,1fr)_minmax(360px,0.9fr)]">
                <article className="rounded-[var(--velmix-radius-xl)] border border-[var(--velmix-border)] bg-[var(--velmix-panel)] p-6 shadow-[var(--velmix-shadow)]">
                    <p className="text-xs font-semibold uppercase tracking-[0.18em] text-[var(--velmix-brand)]">
                        Backoffice de caja
                    </p>
                    <h2 className="mt-2 text-2xl font-semibold">Historial y contexto operativo</h2>
                    <p className="mt-2 text-sm leading-6 text-[var(--velmix-muted)]">
                        Selecciona una sesion para ver profitability, denominaciones y movimientos. Si hay caja abierta, el detalle se fija por defecto ahi.
                    </p>

                    <div className="mt-5 rounded-[var(--velmix-radius-lg)] border border-[var(--velmix-border)] bg-[var(--velmix-panel-strong)] p-4">
                        <CashSessionHistoryTable
                            sessions={history}
                            isFetching={historyQuery.isFetching}
                            selectedSessionId={selectedSessionId}
                            onSelectSession={setSelectedSessionId}
                        />
                    </div>
                </article>

                <article className="rounded-[var(--velmix-radius-xl)] border border-[var(--velmix-border)] bg-[var(--velmix-panel)] p-6 shadow-[var(--velmix-shadow)]">
                    <p className="text-xs font-semibold uppercase tracking-[0.18em] text-[var(--velmix-brand)]">
                        Operacion en vivo
                    </p>
                    <h2 className="mt-2 text-2xl font-semibold">
                        {currentSession !== null ? 'Caja abierta' : 'Abrir nueva caja'}
                    </h2>
                    <p className="mt-2 text-sm leading-6 text-[var(--velmix-muted)]">
                        Desde aqui abrimos caja, registramos movimientos manuales y cerramos caja cuando corresponda.
                    </p>

                    <div className="mt-5 space-y-5 rounded-[var(--velmix-radius-lg)] border border-[var(--velmix-border)] bg-[var(--velmix-panel-strong)] p-4">
                        {currentSession === null ? (
                            canOpenSession ? (
                                <OpenCashSessionForm
                                    isPending={openMutation.isPending}
                                    errorMessage={openMutation.isError ? describeApiError(openMutation.error) : null}
                                    onSubmit={handleOpenSession}
                                />
                            ) : (
                                <StatePanel
                                    tone="warning"
                                    title="Sin permiso de apertura"
                                    description="El usuario actual no tiene el permiso `cash.session.open` para iniciar una nueva caja."
                                />
                            )
                        ) : (
                            <>
                                {canCreateMovement ? (
                                    <ManualCashMovementForm
                                        isPending={movementMutation.isPending}
                                        errorMessage={movementMutation.isError ? describeApiError(movementMutation.error) : null}
                                        onSubmit={handleCreateMovement}
                                    />
                                ) : (
                                    <StatePanel
                                        tone="warning"
                                        title="Sin permiso de movimientos"
                                        description="El usuario actual no tiene el permiso `cash.movement.create` para registrar ingresos o egresos manuales."
                                    />
                                )}

                                {canCloseSession ? (
                                    <CloseCashSessionForm
                                        isPending={closeMutation.isPending}
                                        errorMessage={closeMutation.isError ? describeApiError(closeMutation.error) : null}
                                        onSubmit={handleCloseSession}
                                    />
                                ) : (
                                    <StatePanel
                                        tone="warning"
                                        title="Sin permiso de cierre"
                                        description="El usuario actual no tiene el permiso `cash.session.close` para cerrar la caja actual."
                                    />
                                )}
                            </>
                        )}
                    </div>
                </article>
            </section>

            <CashSessionDetailPanel
                session={selectedSession}
                detail={detailQuery.data}
                movements={movementsQuery.data}
                isDetailLoading={detailQuery.isLoading || detailQuery.isFetching}
                isMovementsLoading={canReadMovements && (movementsQuery.isLoading || movementsQuery.isFetching)}
                isDetailError={detailQuery.isError}
                detailErrorMessage={detailQuery.isError ? describeApiError(detailQuery.error) : null}
                movementsErrorMessage={movementsQuery.isError ? describeApiError(movementsQuery.error) : null}
                canReadMovements={canReadMovements}
                onRefresh={() => {
                    void Promise.all([
                        detailQuery.refetch(),
                        canReadMovements ? movementsQuery.refetch() : Promise.resolve(),
                    ]);
                }}
            />
        </div>
    );
}

interface MetricCardProps {
    label: string;
    value: string;
    help: string;
}

function MetricCard({ label, value, help }: MetricCardProps) {
    return (
        <article className="velmix-metric-card p-5">
            <p className="velmix-kicker text-[var(--velmix-muted)]">{label}</p>
            <p className="mt-2 text-3xl font-black tracking-[-0.06em]">{value}</p>
            <p className="mt-3 text-sm leading-6 text-[var(--velmix-muted)]">{help}</p>
        </article>
    );
}
