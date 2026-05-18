import { StatusBadge, type StatusBadgeTone } from '@/shared/components/StatusBadge';
import type { OperationsHealthGate } from '@/modules/platform/api/controlTower';

interface HealthGateGridProps {
    gates: OperationsHealthGate[];
}

export function HealthGateGrid({ gates }: HealthGateGridProps) {
    return (
        <section className="rounded-[var(--velmix-radius-xl)] border border-[var(--velmix-border)] bg-[var(--velmix-panel)] p-6 shadow-[var(--velmix-shadow)]">
            <div className="mb-5 flex flex-col gap-2">
                <p className="text-xs font-semibold uppercase tracking-[0.18em] text-[var(--velmix-brand)]">
                    Health gates
                </p>
                <h2 className="text-2xl font-semibold">Top health gates operativos</h2>
                <p className="text-sm leading-6 text-[var(--velmix-muted)]">
                    El briefing ya prioriza los gates mas delicados del dia para que el frontend muestre foco operativo, no solo volumen de datos.
                </p>
            </div>

            <div className="grid gap-4 xl:grid-cols-2">
                {gates.map((gate) => (
                    <article
                        key={gate.code}
                        className="rounded-[var(--velmix-radius-lg)] border border-[var(--velmix-border)] bg-[var(--velmix-panel-strong)] p-4"
                    >
                        <div className="flex flex-col gap-3 md:flex-row md:items-start md:justify-between">
                            <div>
                                <h3 className="text-lg font-semibold">{gate.label}</h3>
                                <p className="mt-2 text-sm leading-6 text-[var(--velmix-muted)]">
                                    {gate.reason ?? 'Sin observacion adicional.'}
                                </p>
                            </div>
                            <StatusBadge label={gate.status} tone={toneForStatus(gate.status)} />
                        </div>

                        {gate.action !== null && (
                            <p className="mt-4 text-sm font-medium text-[var(--velmix-ink)]">{gate.action}</p>
                        )}

                        {gate.path !== null && (
                            <p className="mt-4 rounded-[var(--velmix-radius-md)] bg-slate-950/90 px-3 py-2 text-xs text-slate-100">
                                {gate.path}
                            </p>
                        )}
                    </article>
                ))}
            </div>
        </section>
    );
}

function toneForStatus(status: string): StatusBadgeTone {
    if (status === 'critical') {
        return 'danger';
    }

    if (status === 'warning') {
        return 'warning';
    }

    if (status === 'ok') {
        return 'success';
    }

    return 'neutral';
}
