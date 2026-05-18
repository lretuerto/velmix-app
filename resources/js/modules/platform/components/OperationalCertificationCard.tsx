import { StatusBadge, type StatusBadgeTone } from '@/shared/components/StatusBadge';
import { formatDateTime } from '@/shared/utils/formatters';
import type {
    ObservabilityBackupManifest,
    PlatformObservabilityReport,
} from '@/modules/platform/api/observability';

interface OperationalCertificationCardProps {
    report: PlatformObservabilityReport;
}

export function OperationalCertificationCard({ report }: OperationalCertificationCardProps) {
    const release =
        extractRelease(report.operational_certification.latest_certificate)
        ?? extractRelease(report.cutover.latest_decision)
        ?? extractRelease(report.promotion.latest_approval)
        ?? extractRelease(report.certification.staging.latest_certification);

    const checkpoints = [
        {
            label: 'Staging certification',
            status: report.certification.staging.status,
            recordedAt: report.certification.staging.latest_certification?.recorded_at as string | null | undefined,
        },
        {
            label: 'Promotion',
            status: report.promotion.status,
            recordedAt: report.promotion.latest_approval?.recorded_at as string | null | undefined,
        },
        {
            label: 'Cutover',
            status: report.cutover.status,
            recordedAt: report.cutover.latest_decision?.recorded_at as string | null | undefined,
        },
        {
            label: 'Operational certification',
            status: report.operational_certification.status,
            recordedAt: report.operational_certification.latest_certificate?.recorded_at as string | null | undefined,
        },
    ];

    return (
        <section className="rounded-[var(--velmix-radius-xl)] border border-[var(--velmix-border)] bg-[var(--velmix-panel)] p-6 shadow-[var(--velmix-shadow)]">
            <div className="mb-5 flex items-start justify-between gap-4">
                <div>
                    <p className="text-xs font-semibold uppercase tracking-[0.18em] text-[var(--velmix-brand)]">
                        Governance
                    </p>
                    <h2 className="mt-2 text-2xl font-semibold">Certificacion operativa del release</h2>
                    <p className="mt-2 text-sm leading-6 text-[var(--velmix-muted)]">
                        Esta tarjeta junta staging, promotion, cutover y operational certification en una sola lectura ejecutiva.
                    </p>
                </div>
                <StatusBadge label={release ?? 'sin release'} tone="info" />
            </div>

            <div className="grid gap-3">
                {checkpoints.map((checkpoint) => (
                    <article
                        key={checkpoint.label}
                        className="flex flex-col gap-3 rounded-[var(--velmix-radius-lg)] border border-[var(--velmix-border)] bg-[var(--velmix-panel-strong)] p-4 md:flex-row md:items-center md:justify-between"
                    >
                        <div>
                            <h3 className="text-base font-semibold">{checkpoint.label}</h3>
                            <p className="mt-1 text-sm text-[var(--velmix-muted)]">
                                Recorded at: {formatDateTime(checkpoint.recordedAt)}
                            </p>
                        </div>
                        <StatusBadge label={checkpoint.status} tone={toneForStatus(checkpoint.status)} />
                    </article>
                ))}
            </div>

            <div className="mt-5 rounded-[var(--velmix-radius-lg)] border border-[var(--velmix-border)] bg-[var(--velmix-panel-strong)] p-4">
                <p className="text-xs font-semibold uppercase tracking-[0.16em] text-[var(--velmix-muted)]">
                    Logging & notifications
                </p>
                <div className="mt-3 flex flex-wrap gap-2">
                    <StatusBadge
                        label={report.logging.structured_logging_enabled ? 'structured logging on' : 'structured logging off'}
                        tone={report.logging.structured_logging_enabled ? 'success' : 'warning'}
                    />
                    <StatusBadge
                        label={report.notifications.slack_enabled ? 'slack on' : 'slack off'}
                        tone={report.notifications.slack_enabled ? 'success' : 'warning'}
                    />
                    <StatusBadge
                        label={report.notifications.webhook_enabled ? 'webhook on' : 'webhook off'}
                        tone={report.notifications.webhook_enabled ? 'success' : 'warning'}
                    />
                </div>
            </div>
        </section>
    );
}

function extractRelease(record: ObservabilityBackupManifest | null | undefined): string | null {
    if (record === null || record === undefined) {
        return null;
    }

    if (typeof record.release === 'string' && record.release !== '') {
        return record.release;
    }

    if (typeof record.release_identifier === 'string' && record.release_identifier !== '') {
        return record.release_identifier;
    }

    return null;
}

function toneForStatus(status: string): StatusBadgeTone {
    if (status === 'critical') {
        return 'danger';
    }

    if (status === 'warning') {
        return 'warning';
    }

    if (status === 'ok' || status === 'approved' || status === 'certified') {
        return 'success';
    }

    return 'neutral';
}
