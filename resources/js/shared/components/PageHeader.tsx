import type { ReactNode } from 'react';

interface PageHeaderProps {
    eyebrow: string;
    title: string;
    description: string;
    actions?: ReactNode;
}

export function PageHeader({ eyebrow, title, description, actions }: PageHeaderProps) {
    return (
        <header className="velmix-card">
            <div className="absolute right-0 top-0 h-full w-1/3 bg-[radial-gradient(circle_at_top_right,rgb(36_95_104_/_0.16),transparent_62%)]" />
            <div className="relative flex flex-col gap-5 px-6 py-6 lg:flex-row lg:items-center lg:justify-between">
                <div className="min-w-0">
                    <div className="flex items-center gap-3">
                        <span className="h-2.5 w-2.5 rounded-full bg-[var(--velmix-brand)] shadow-[0_0_0_7px_rgb(169_87_43_/_0.13)]" />
                        <p className="velmix-kicker">{eyebrow}</p>
                    </div>
                    <h1 className="mt-3 max-w-5xl text-4xl font-black leading-[0.98] tracking-[-0.06em] md:text-5xl">
                        {title}
                    </h1>
                    <p className="mt-4 max-w-4xl text-[15px] leading-7 text-[var(--velmix-muted)]">{description}</p>
                </div>
                {actions !== undefined && (
                    <div className="shrink-0 rounded-[var(--velmix-radius-lg)] border border-[var(--velmix-border)] bg-white/55 p-2 shadow-[0_12px_30px_rgb(12_32_27_/_0.06)]">
                        {actions}
                    </div>
                )}
            </div>
        </header>
    );
}
