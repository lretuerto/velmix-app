import { describeApiError } from '@/core/api/errors';
import { StatePanel } from '@/core/ui/feedback/StatePanel';
import { HealthGateGrid } from '@/modules/platform/components/HealthGateGrid';
import { OperationalCertificationCard } from '@/modules/platform/components/OperationalCertificationCard';
import { RecoveryStatusCard } from '@/modules/platform/components/RecoveryStatusCard';
import { useControlTowerBriefing } from '@/modules/platform/hooks/useControlTowerBriefing';
import { usePlatformObservability } from '@/modules/platform/hooks/usePlatformObservability';
import { PageHeader } from '@/shared/components/PageHeader';
import { StatusBadge, type StatusBadgeTone } from '@/shared/components/StatusBadge';
import { formatCurrency, formatDate, formatDateTime, formatNumber } from '@/shared/utils/formatters';

export function PlatformOverviewPage() {
    const observabilityQuery = usePlatformObservability();
    const briefingQuery = useControlTowerBriefing({
        historyDays: 7,
        billingDays: 7,
        financeDaysAhead: 7,
        priorityLimit: 5,
        failureLimit: 5,
        staleFollowUpDays: 3,
    });
    const isRefreshing = observabilityQuery.isFetching || briefingQuery.isFetching;

    const handleRefresh = async () => {
        await Promise.all([observabilityQuery.refetch(), briefingQuery.refetch()]);
    };

    const observability = observabilityQuery.data;
    const briefing = briefingQuery.data;

    return (
        <div className="space-y-6">
            <PageHeader
                eyebrow="Platform"
                title="Observabilidad y control tower"
                description="El dashboard ahora consume los payloads reales de observabilidad tecnica y briefing ejecutivo del control tower para entregar lectura operativa util desde el frontend."
                actions={
                    <button
                        type="button"
                        className="rounded-[var(--velmix-radius-md)] border border-[var(--velmix-border-strong)] bg-[var(--velmix-panel-strong)] px-4 py-2 text-sm font-semibold text-[var(--velmix-ink)] transition hover:border-[var(--velmix-brand)] disabled:cursor-not-allowed disabled:opacity-60"
                        onClick={() => {
                            void handleRefresh();
                        }}
                        disabled={isRefreshing}
                    >
                        {isRefreshing ? 'Refrescando...' : 'Refrescar dashboard'}
                    </button>
                }
            />

            {observabilityQuery.isLoading && briefingQuery.isLoading && (
                <StatePanel
                    tone="neutral"
                    title="Cargando dashboard de plataforma"
                    description="Estamos consultando observabilidad tecnica, cadena de recovery y briefing ejecutivo del control tower."
                />
            )}

            {briefing !== undefined && (
                <section className="grid gap-4 xl:grid-cols-4 md:grid-cols-2">
                    <MetricCard
                        label="Overall status"
                        value={briefing.executive_summary.overall_status}
                        help="Estado consolidado del control tower live."
                    />
                    <MetricCard
                        label="Billing failed backlog"
                        value={formatNumber(briefing.executive_summary.billing_failed_backlog_count)}
                        help="Eventos fallidos que siguen pendientes de atencion."
                    />
                    <MetricCard
                        label="Finance overdue total"
                        value={formatCurrency(briefing.executive_summary.finance_overdue_total)}
                        help="Exposicion vencida agregada del corte actual."
                    />
                    <MetricCard
                        label="Open operations alerts"
                        value={formatNumber(briefing.executive_summary.operations_open_alert_count)}
                        help="Alertas operativas abiertas en la cola unificada."
                    />
                </section>
            )}

            <section className="grid gap-6 xl:grid-cols-[minmax(0,1.45fr)_minmax(320px,1fr)]">
                {briefingQuery.isError ? (
                    <StatePanel
                        tone="danger"
                        title="No pudimos cargar el briefing operativo"
                        description={describeApiError(briefingQuery.error)}
                    />
                ) : briefing !== undefined ? (
                    <HealthGateGrid gates={briefing.highlights.top_health_gates} />
                ) : null}

                {briefingQuery.isError ? null : briefing !== undefined ? (
                    <article className="rounded-[var(--velmix-radius-xl)] border border-[var(--velmix-border)] bg-[var(--velmix-panel)] p-6 shadow-[var(--velmix-shadow)]">
                        <div className="flex items-start justify-between gap-4">
                            <div>
                                <p className="text-xs font-semibold uppercase tracking-[0.18em] text-[var(--velmix-brand)]">
                                    Briefing
                                </p>
                                <h2 className="mt-2 text-2xl font-semibold">Insights y drift</h2>
                            </div>
                            <StatusBadge
                                label={briefing.executive_summary.overall_status}
                                tone={toneForStatus(briefing.executive_summary.overall_status)}
                            />
                        </div>

                        <dl className="mt-5 grid gap-3 rounded-[var(--velmix-radius-lg)] border border-[var(--velmix-border)] bg-[var(--velmix-panel-strong)] p-4 text-sm text-[var(--velmix-muted)]">
                            <MetricRow label="Fecha briefing" value={formatDate(briefing.date)} />
                            <MetricRow label="Ventana historica" value={`${briefing.windows.history_days} dias`} />
                            <MetricRow
                                label="Worst day"
                                value={briefing.history.summary.worst_day?.date ?? 'Sin peores dias registrados'}
                            />
                            <MetricRow
                                label="Snapshot drift"
                                value={briefing.highlights.snapshot_drift?.movement ?? 'Sin snapshot aplicado'}
                            />
                        </dl>

                        <div className="mt-5 space-y-4">
                            <section>
                                <p className="text-sm font-semibold uppercase tracking-[0.16em] text-[var(--velmix-muted)]">
                                    Key actions
                                </p>
                                <ul className="mt-3 space-y-2 text-sm leading-6 text-[var(--velmix-muted)]">
                                    {briefing.highlights.key_actions.length === 0 ? (
                                        <li>No hay acciones recomendadas en este corte.</li>
                                    ) : (
                                        briefing.highlights.key_actions.map((action) => <li key={action}>- {action}</li>)
                                    )}
                                </ul>
                            </section>

                            <section>
                                <p className="text-sm font-semibold uppercase tracking-[0.16em] text-[var(--velmix-muted)]">
                                    Insights
                                </p>
                                <ul className="mt-3 space-y-2 text-sm leading-6 text-[var(--velmix-muted)]">
                                    {briefing.highlights.insights.map((insight) => (
                                        <li key={insight}>- {insight}</li>
                                    ))}
                                </ul>
                            </section>
                        </div>
                    </article>
                ) : null}
            </section>

            <section className="grid gap-6 xl:grid-cols-2">
                {observabilityQuery.isError ? (
                    <StatePanel
                        tone="danger"
                        title="No pudimos cargar observabilidad tecnica"
                        description={describeApiError(observabilityQuery.error)}
                    />
                ) : observability !== undefined ? (
                    <RecoveryStatusCard report={observability} />
                ) : null}

                {observabilityQuery.isError ? null : observability !== undefined ? (
                    <OperationalCertificationCard report={observability} />
                ) : null}
            </section>

            {briefing !== undefined && (
                <section className="grid gap-6 xl:grid-cols-[minmax(0,1fr)_minmax(0,1fr)]">
                    <article className="rounded-[var(--velmix-radius-xl)] border border-[var(--velmix-border)] bg-[var(--velmix-panel)] p-6 shadow-[var(--velmix-shadow)]">
                        <p className="text-xs font-semibold uppercase tracking-[0.18em] text-[var(--velmix-brand)]">
                            Trend
                        </p>
                        <h2 className="mt-2 text-2xl font-semibold">Status breakdown de la ventana</h2>
                        <div className="mt-5 grid gap-4 md:grid-cols-3">
                            <TrendMetric
                                label="OK"
                                value={formatNumber(briefing.history.summary.status_breakdown.ok_count)}
                                tone="success"
                            />
                            <TrendMetric
                                label="Warning"
                                value={formatNumber(briefing.history.summary.status_breakdown.warning_count)}
                                tone="warning"
                            />
                            <TrendMetric
                                label="Critical"
                                value={formatNumber(briefing.history.summary.status_breakdown.critical_count)}
                                tone="danger"
                            />
                        </div>

                        <div className="mt-5 overflow-x-auto">
                            <table className="min-w-full text-left text-sm">
                                <thead className="text-[var(--velmix-muted)]">
                                    <tr>
                                        <th className="px-3 py-2 font-semibold">Fecha</th>
                                        <th className="px-3 py-2 font-semibold">Estado</th>
                                        <th className="px-3 py-2 font-semibold">Billing failed</th>
                                        <th className="px-3 py-2 font-semibold">Finance overdue</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    {briefing.history.timeline.slice(-5).map((item) => (
                                        <tr key={item.date} className="border-t border-[var(--velmix-border)]">
                                            <td className="px-3 py-3">{formatDate(item.date)}</td>
                                            <td className="px-3 py-3">
                                                <StatusBadge label={item.overall_status} tone={toneForStatus(item.overall_status)} />
                                            </td>
                                            <td className="px-3 py-3">{formatNumber(item.billing_failed_backlog_count)}</td>
                                            <td className="px-3 py-3">{formatCurrency(item.finance_overdue_total)}</td>
                                        </tr>
                                    ))}
                                </tbody>
                            </table>
                        </div>
                    </article>

                    {observability !== undefined && (
                        <article className="rounded-[var(--velmix-radius-xl)] border border-[var(--velmix-border)] bg-[var(--velmix-panel)] p-6 shadow-[var(--velmix-shadow)]">
                            <p className="text-xs font-semibold uppercase tracking-[0.18em] text-[var(--velmix-brand)]">
                                Technical posture
                            </p>
                            <h2 className="mt-2 text-2xl font-semibold">Observabilidad tecnica activa</h2>
                            <dl className="mt-5 grid gap-3 rounded-[var(--velmix-radius-lg)] border border-[var(--velmix-border)] bg-[var(--velmix-panel-strong)] p-4 text-sm text-[var(--velmix-muted)]">
                                <MetricRow label="Checked at" value={formatDateTime(observability.checked_at)} />
                                <MetricRow
                                    label="Request correlation"
                                    value={`${observability.request_correlation.request_id_header} / ${observability.request_correlation.response_header}`}
                                />
                                <MetricRow
                                    label="Effective logging"
                                    value={(observability.logging.effective_channels ?? []).join(', ') || 'N/A'}
                                />
                                <MetricRow
                                    label="Recommendations"
                                    value={formatNumber(observability.recommendations.length)}
                                />
                            </dl>

                            {observability.recommendations.length > 0 && (
                                <ul className="mt-5 space-y-2 text-sm leading-6 text-[var(--velmix-muted)]">
                                    {observability.recommendations.slice(0, 4).map((recommendation) => (
                                        <li key={recommendation}>- {recommendation}</li>
                                    ))}
                                </ul>
                            )}
                        </article>
                    )}
                </section>
            )}
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

interface MetricRowProps {
    label: string;
    value: string;
}

function MetricRow({ label, value }: MetricRowProps) {
    return (
        <div className="grid gap-1">
            <dt className="font-semibold text-[var(--velmix-ink)]">{label}</dt>
            <dd>{value}</dd>
        </div>
    );
}

interface TrendMetricProps {
    label: string;
    value: string;
    tone: StatusBadgeTone;
}

function TrendMetric({ label, value, tone }: TrendMetricProps) {
    return (
        <div className="velmix-card-strong p-4">
            <div className="flex items-center justify-between gap-3">
                <p className="text-sm font-semibold">{label}</p>
                <StatusBadge label={label} tone={tone} />
            </div>
            <p className="mt-3 text-2xl font-black tracking-[-0.04em]">{value}</p>
        </div>
    );
}

function toneForStatus(status: string): StatusBadgeTone {
    if (status === 'critical') {
        return 'danger';
    }

    if (status === 'warning') {
        return 'warning';
    }

    if (status === 'ok' || status === 'ready' || status === 'approved' || status === 'certified') {
        return 'success';
    }

    return 'neutral';
}
