import { StatusBadge, type StatusBadgeTone } from '@/shared/components/StatusBadge';
import { formatDateTime, formatNumber } from '@/shared/utils/formatters';
import type { PlatformObservabilityReport } from '@/modules/platform/api/observability';

interface RecoveryStatusCardProps {
    report: PlatformObservabilityReport;
}

export function RecoveryStatusCard({ report }: RecoveryStatusCardProps) {
    const backup = report.recovery.backup;
    const restoreDrill = report.recovery.restore_drill;
    const latestBackup = backup.latest_backup ?? null;
    const latestDrill = restoreDrill.latest_drill ?? null;
    const channels = report.delivery.channels ?? [];

    return (
        <section className="rounded-[var(--velmix-radius-xl)] border border-[var(--velmix-border)] bg-[var(--velmix-panel)] p-6 shadow-[var(--velmix-shadow)]">
            <div className="mb-5 flex items-start justify-between gap-4">
                <div>
                    <p className="text-xs font-semibold uppercase tracking-[0.18em] text-[var(--velmix-brand)]">
                        Recovery
                    </p>
                    <h2 className="mt-2 text-2xl font-semibold">Backup, restore y delivery operativo</h2>
                </div>
                <StatusBadge label={report.status} tone={toneForStatus(report.status)} />
            </div>

            <div className="grid gap-4">
                <article className="rounded-[var(--velmix-radius-lg)] border border-[var(--velmix-border)] bg-[var(--velmix-panel-strong)] p-4">
                    <div className="flex items-center justify-between gap-3">
                        <h3 className="text-lg font-semibold">Backup readiness</h3>
                        <StatusBadge label={backup.status} tone={toneForStatus(backup.status)} />
                    </div>
                    <dl className="mt-4 grid gap-3 text-sm text-[var(--velmix-muted)]">
                        <div>
                            <dt className="font-semibold text-[var(--velmix-ink)]">Artefacto</dt>
                            <dd className="mt-1 break-all">{latestBackup?.artifact ?? 'N/A'}</dd>
                        </div>
                        <div className="grid gap-3 md:grid-cols-3">
                            <MetricItem label="Driver" value={String(latestBackup?.driver ?? 'N/A')} />
                            <MetricItem label="Tamano" value={latestBackup?.size_bytes !== undefined ? `${formatNumber(latestBackup.size_bytes)} bytes` : 'N/A'} />
                            <MetricItem label="Registrado" value={formatDateTime(latestBackup?.recorded_at)} />
                        </div>
                    </dl>
                </article>

                <article className="rounded-[var(--velmix-radius-lg)] border border-[var(--velmix-border)] bg-[var(--velmix-panel-strong)] p-4">
                    <div className="flex items-center justify-between gap-3">
                        <h3 className="text-lg font-semibold">Restore drill</h3>
                        <StatusBadge label={restoreDrill.status} tone={toneForStatus(restoreDrill.status)} />
                    </div>
                    <dl className="mt-4 grid gap-3 text-sm text-[var(--velmix-muted)]">
                        <MetricItem label="Drilled at" value={formatDateTime(latestDrill?.drilled_at as string | null | undefined)} />
                        <div>
                            <dt className="font-semibold text-[var(--velmix-ink)]">Reporte</dt>
                            <dd className="mt-1 break-all">{String(latestDrill?.report_path ?? 'N/A')}</dd>
                        </div>
                    </dl>
                </article>

                <article className="rounded-[var(--velmix-radius-lg)] border border-[var(--velmix-border)] bg-[var(--velmix-panel-strong)] p-4">
                    <div className="flex items-center justify-between gap-3">
                        <h3 className="text-lg font-semibold">Outbound delivery</h3>
                        <StatusBadge
                            label={`${formatNumber(report.delivery.candidate_alert_count ?? 0)} candidates`}
                            tone={(report.delivery.candidate_alert_count ?? 0) > 0 ? 'warning' : 'neutral'}
                        />
                    </div>
                    <div className="mt-4 flex flex-wrap gap-2">
                        {channels.map((channel) => (
                            <StatusBadge
                                key={String(channel.channel)}
                                label={`${channel.channel}:${channel.status}`}
                                tone={toneForStatus(String(channel.status))}
                            />
                        ))}
                    </div>
                </article>
            </div>
        </section>
    );
}

interface MetricItemProps {
    label: string;
    value: string;
}

function MetricItem({ label, value }: MetricItemProps) {
    return (
        <div>
            <dt className="font-semibold text-[var(--velmix-ink)]">{label}</dt>
            <dd className="mt-1">{value}</dd>
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

    if (status === 'ok' || status === 'ready' || status === 'healthy') {
        return 'success';
    }

    return 'neutral';
}
