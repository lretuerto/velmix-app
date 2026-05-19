import { cn } from '@/shared/utils/cn';

export type StatusBadgeTone = 'neutral' | 'success' | 'warning' | 'danger' | 'info';

interface StatusBadgeProps {
    label: string;
    tone?: StatusBadgeTone;
}

const toneClassMap: Record<StatusBadgeTone, string> = {
    neutral: 'border-[var(--velmix-border)] bg-white/80 text-[var(--velmix-muted)]',
    success: 'border-[rgb(22_114_80_/_0.24)] bg-[var(--velmix-success-soft)] text-[var(--velmix-success)]',
    warning: 'border-[rgb(157_104_27_/_0.28)] bg-[var(--velmix-warning-soft)] text-[var(--velmix-warning)]',
    danger: 'border-[rgb(159_52_69_/_0.24)] bg-[var(--velmix-danger-soft)] text-[var(--velmix-danger)]',
    info: 'border-[rgb(36_95_104_/_0.22)] bg-[var(--velmix-accent-soft)] text-[var(--velmix-info)]',
};

export function StatusBadge({ label, tone = 'neutral' }: StatusBadgeProps) {
    return (
        <span
            className={cn(
                'inline-flex items-center rounded-full border px-2.5 py-1 text-[11px] font-black uppercase tracking-[0.16em] shadow-[inset_0_1px_0_rgb(255_255_255_/_0.65)]',
                toneClassMap[tone],
            )}
        >
            {label}
        </span>
    );
}
