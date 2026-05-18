import type { ReactNode } from 'react';
import { cn } from '@/shared/utils/cn';

interface StatePanelProps {
    title: string;
    description: string;
    tone?: 'neutral' | 'warning' | 'danger' | 'success';
    actions?: ReactNode;
}

const toneClassMap: Record<NonNullable<StatePanelProps['tone']>, string> = {
    neutral: 'border-[var(--velmix-border)] bg-[var(--velmix-panel)] text-[var(--velmix-ink)]',
    warning: 'border-amber-300 bg-amber-50 text-amber-900',
    danger: 'border-red-300 bg-red-50 text-red-900',
    success: 'border-emerald-300 bg-emerald-50 text-emerald-900',
};

export function StatePanel({ title, description, tone = 'neutral', actions }: StatePanelProps) {
    return (
        <section
            className={cn(
                'rounded-[var(--velmix-radius-xl)] border p-6 shadow-[var(--velmix-shadow)]',
                toneClassMap[tone],
            )}
        >
            <p className="mb-2 text-sm font-semibold uppercase tracking-[0.18em] text-current/75">VELMiX Frontend</p>
            <h2 className="mb-2 text-2xl font-semibold">{title}</h2>
            <p className="max-w-2xl text-sm leading-6 text-current/80">{description}</p>
            {actions !== undefined && <div className="mt-4">{actions}</div>}
        </section>
    );
}
