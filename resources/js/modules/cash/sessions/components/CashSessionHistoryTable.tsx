import { StatusBadge, type StatusBadgeTone } from '@/shared/components/StatusBadge';
import { cn } from '@/shared/utils/cn';
import { formatCurrency, formatDateTime } from '@/shared/utils/formatters';
import type { CashSessionSummary } from '@/modules/cash/sessions/types';

interface CashSessionHistoryTableProps {
    sessions: CashSessionSummary[];
    isFetching: boolean;
    selectedSessionId: number | null;
    onSelectSession: (sessionId: number) => void;
}

export function CashSessionHistoryTable({
    sessions,
    isFetching,
    selectedSessionId,
    onSelectSession,
}: CashSessionHistoryTableProps) {
    return (
        <div className="overflow-x-auto">
            <table className="min-w-full text-left text-sm">
                <thead className="text-[var(--velmix-muted)]">
                    <tr>
                        <th className="px-3 py-3 font-semibold">Sesion</th>
                        <th className="px-3 py-3 font-semibold">Estado</th>
                        <th className="px-3 py-3 font-semibold">Expected</th>
                        <th className="px-3 py-3 font-semibold">Counted</th>
                        <th className="px-3 py-3 font-semibold">Apertura</th>
                        <th className="px-3 py-3 font-semibold text-right">Acciones</th>
                    </tr>
                </thead>
                <tbody>
                    {sessions.length === 0 ? (
                        <tr>
                            <td className="px-3 py-6 text-[var(--velmix-muted)]" colSpan={6}>
                                {isFetching ? 'Actualizando historial de caja...' : 'No hay sesiones de caja registradas.'}
                            </td>
                        </tr>
                    ) : (
                        sessions.map((session) => {
                            const isSelected = session.id === selectedSessionId;

                            return (
                                <tr
                                    key={session.id}
                                    className={cn(
                                        'border-t border-[var(--velmix-border)] transition',
                                        isSelected && 'bg-[var(--velmix-brand-soft)]/60',
                                    )}
                                >
                                    <td className="px-3 py-3">
                                        <p className="font-semibold">Caja #{session.id}</p>
                                        <p className="text-[var(--velmix-muted)]">{session.opened_by.name ?? 'N/A'}</p>
                                    </td>
                                    <td className="px-3 py-3">
                                        <StatusBadge label={session.status} tone={toneForCashStatus(session.status)} />
                                    </td>
                                    <td className="px-3 py-3">{formatCurrency(session.expected_amount)}</td>
                                    <td className="px-3 py-3">
                                        {session.counted_amount !== null ? formatCurrency(session.counted_amount) : 'Pendiente'}
                                    </td>
                                    <td className="px-3 py-3">{formatDateTime(session.opened_at)}</td>
                                    <td className="px-3 py-3 text-right">
                                        <button
                                            type="button"
                                            className="rounded-[var(--velmix-radius-md)] border border-[var(--velmix-border-strong)] px-3 py-2 text-xs font-semibold transition hover:border-[var(--velmix-brand)]"
                                            onClick={() => onSelectSession(session.id)}
                                        >
                                            Ver detalle
                                        </button>
                                    </td>
                                </tr>
                            );
                        })
                    )}
                </tbody>
            </table>
        </div>
    );
}

function toneForCashStatus(status: string): StatusBadgeTone {
    if (status === 'open') {
        return 'success';
    }

    if (status === 'closed') {
        return 'info';
    }

    return 'neutral';
}
