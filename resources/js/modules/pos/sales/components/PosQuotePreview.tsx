import { useEffect, useState } from 'react';
import { StatePanel } from '@/core/ui/feedback/StatePanel';
import type { PricingQuote } from '@/modules/pricing/quotes/types';
import { StatusBadge } from '@/shared/components/StatusBadge';
import { formatCurrency, formatDateTime } from '@/shared/utils/formatters';

interface PosQuotePreviewProps {
    quote: PricingQuote | null;
    isConfirming: boolean;
    isRequoting: boolean;
    onConfirm: () => void;
    onDiscard: () => void;
    onRequote: () => void;
}

export function PosQuotePreview({ quote, isConfirming, isRequoting, onConfirm, onDiscard, onRequote }: PosQuotePreviewProps) {
    const [nowMs, setNowMs] = useState(() => Date.now());

    useEffect(() => {
        const intervalId = window.setInterval(() => setNowMs(Date.now()), 15_000);

        return () => window.clearInterval(intervalId);
    }, []);

    if (quote === null) {
        return (
            <StatePanel
                tone="neutral"
                title="Sin cotizacion activa"
                description="Completa el carrito y genera un quote para revisar precio base, promociones de laboratorio y total final antes de consumirlo en el checkout."
            />
        );
    }

    const expiresAtMs = new Date(quote.expires_at).getTime();
    const isExpired = expiresAtMs <= nowMs;
    const expiresInSeconds = Math.max(0, Math.ceil((expiresAtMs - nowMs) / 1_000));
    const expiresInLabel = expiresInSeconds > 60
        ? `${Math.ceil(expiresInSeconds / 60)} min`
        : `${expiresInSeconds} s`;

    return (
        <div className="space-y-4">
            <div className="velmix-card-strong overflow-hidden">
                <div className="border-b border-[var(--velmix-border)] bg-[var(--velmix-sidebar)] px-4 py-4 text-white">
                <div className="flex flex-col gap-3 md:flex-row md:items-start md:justify-between">
                    <div>
                        <p className="text-[10px] font-black uppercase tracking-[0.24em] text-[#f0b27f]">
                            Quote server-side
                        </p>
                        <h3 className="mt-2 text-xl font-black tracking-[-0.04em]">
                            Quote #{quote.id} · {quote.price_list?.code ?? 'sin lista'}
                        </h3>
                        <p className="mt-2 text-sm leading-6 text-white/66">
                            Expira {formatDateTime(quote.expires_at)}
                            {isExpired ? '. Este snapshot ya no debe confirmarse.' : ` · quedan aprox. ${expiresInLabel}.`}
                            {' '}Si ajustas el formulario, vuelve a cotizar antes de confirmar.
                        </p>
                    </div>
                    <div className="flex flex-wrap items-center gap-2">
                        <StatusBadge tone={isExpired ? 'danger' : 'info'} label={isExpired ? 'Expirado' : quote.status} />
                        <StatusBadge tone="neutral" label={quote.payment_method} />
                    </div>
                </div>
                </div>

                <div className="grid gap-3 p-4 sm:grid-cols-3">
                    <SummaryCard label="Subtotal" value={formatCurrency(quote.summary.subtotal_amount, quote.currency)} />
                    <SummaryCard label="Descuento" value={formatCurrency(quote.summary.discount_amount, quote.currency)} />
                    <SummaryCard label="Total" value={formatCurrency(quote.summary.total_amount, quote.currency)} accent />
                </div>
            </div>

            <div className="velmix-card-strong p-4">
                <div className="flex flex-col gap-2">
                    <p className="text-sm font-black">Lineas cotizadas</p>
                    <p className="text-xs leading-5 text-[var(--velmix-muted)]">
                        El backend ya resolvio lista, promociones elegibles y sponsor de laboratorio para cada linea.
                    </p>
                </div>

                <div className="mt-4 space-y-4">
                    {quote.items.map((item) => (
                        <article
                            key={item.id}
                            className="rounded-[var(--velmix-radius-md)] border border-[var(--velmix-border)] bg-white p-4 shadow-[0_8px_18px_rgb(16_35_30_/_0.04)]"
                        >
                            <div className="flex flex-col gap-2 md:flex-row md:items-start md:justify-between">
                                <div>
                                    <p className="text-sm font-black">
                                        {item.product_sku} · {item.product_name}
                                    </p>
                                    <p className="text-xs leading-5 text-[var(--velmix-muted)]">
                                        Cantidad {item.requested_quantity} · Fuente {item.commercial_context.price_source}
                                    </p>
                                </div>
                                <div className="text-sm font-black">
                                    {formatCurrency(item.line_total, quote.currency)}
                                </div>
                            </div>

                            <div className="mt-3 grid gap-3 md:grid-cols-3">
                                <SummaryCard label="Base" value={formatCurrency(item.base_unit_price, quote.currency)} compact />
                                <SummaryCard label="Final" value={formatCurrency(item.final_unit_price, quote.currency)} compact />
                                <SummaryCard label="Ahorro" value={formatCurrency(item.line_discount_amount, quote.currency)} compact />
                            </div>

                            <div className="mt-3 space-y-2">
                                {item.adjustments.map((adjustment) => (
                                    <div
                                        key={adjustment.id}
                                        className="rounded-[var(--velmix-radius-md)] border border-[var(--velmix-border)] bg-white px-3 py-2 text-xs leading-5 text-[var(--velmix-muted)]"
                                    >
                                        <p className="font-semibold text-[var(--velmix-ink)]">
                                            {adjustment.description}
                                        </p>
                                        <p>
                                            Delta {formatCurrency(adjustment.total_delta, quote.currency)}
                                            {adjustment.promotion_code !== null ? ` · ${adjustment.promotion_code}` : ''}
                                            {adjustment.sponsor_supplier?.name !== null && adjustment.sponsor_supplier?.name !== undefined
                                                ? ` · ${adjustment.sponsor_supplier.name}`
                                                : ''}
                                        </p>
                                    </div>
                                ))}
                            </div>
                        </article>
                    ))}
                </div>
            </div>

            {quote.applied_promotions.length > 0 && (
                <div className="velmix-card-strong p-4">
                    <p className="text-sm font-black">Promociones aplicadas</p>
                    <div className="mt-3 space-y-2">
                        {quote.applied_promotions.map((promotion) => (
                            <div
                                key={promotion.id}
                                className="rounded-[var(--velmix-radius-md)] border border-[var(--velmix-border)] bg-[var(--velmix-panel-strong)] px-3 py-2 text-sm"
                            >
                                <div className="flex flex-col gap-1 md:flex-row md:items-center md:justify-between">
                                    <p className="font-black">
                                        {promotion.code ?? 'PROMO'} · {promotion.name ?? 'Promocion aplicada'}
                                    </p>
                                    <p>{formatCurrency(promotion.discount_amount, quote.currency)}</p>
                                </div>
                                {promotion.sponsor_supplier?.name !== null && promotion.sponsor_supplier?.name !== undefined && (
                                    <p className="mt-1 text-xs text-[var(--velmix-muted)]">
                                        Sponsor: {promotion.sponsor_supplier.name}
                                    </p>
                                )}
                            </div>
                        ))}
                    </div>
                </div>
            )}

            {quote.warnings.length > 0 && (
                <StatePanel
                    tone="warning"
                    title="Advertencias del quote"
                    description={quote.warnings.join(' ')}
                />
            )}

            {isExpired && (
                <StatePanel
                    tone="warning"
                    title="Quote expirado"
                    description="La venta ya no deberia confirmarse con este snapshot. Vuelve a cotizar para refrescar el precio y las promociones."
                    actions={
                        <button
                            type="button"
                            className="inline-flex items-center rounded-[var(--velmix-radius-md)] bg-amber-900 px-4 py-2 text-sm font-semibold text-white transition hover:opacity-90 disabled:cursor-not-allowed disabled:opacity-60"
                            onClick={onRequote}
                            disabled={isRequoting || isConfirming}
                        >
                            {isRequoting ? 'Recotizando...' : 'Recotizar carrito'}
                        </button>
                    }
                />
            )}

            <div className="flex flex-wrap items-center gap-3">
                <button
                    type="button"
                    className="velmix-button-primary inline-flex items-center px-4 py-2 text-sm disabled:cursor-not-allowed disabled:opacity-60"
                    onClick={onConfirm}
                    disabled={isConfirming || isExpired}
                >
                    {isConfirming ? 'Registrando venta...' : 'Confirmar venta con quote'}
                </button>
                {!isExpired && (
                    <button
                        type="button"
                        className="velmix-button-secondary inline-flex items-center px-4 py-2 text-sm disabled:cursor-not-allowed disabled:opacity-60"
                        onClick={onRequote}
                        disabled={isRequoting || isConfirming}
                    >
                        {isRequoting ? 'Recotizando...' : 'Actualizar quote'}
                    </button>
                )}
                <button
                    type="button"
                    className="velmix-button-secondary inline-flex items-center px-4 py-2 text-sm"
                    onClick={onDiscard}
                    disabled={isConfirming}
                >
                    Descartar quote
                </button>
            </div>
        </div>
    );
}

interface SummaryCardProps {
    label: string;
    value: string;
    accent?: boolean;
    compact?: boolean;
}

function SummaryCard({ label, value, accent = false, compact = false }: SummaryCardProps) {
    return (
        <div className={`rounded-[var(--velmix-radius-md)] border ${accent ? 'border-[var(--velmix-brand)] bg-[var(--velmix-brand-soft)]' : 'border-[var(--velmix-border)] bg-white'} ${compact ? 'p-3' : 'p-4'}`}>
            <p className="text-[10px] font-black uppercase tracking-[0.18em] text-[var(--velmix-muted)]">{label}</p>
            <p className={`mt-2 ${compact ? 'text-base' : 'text-lg'} font-black tracking-[-0.03em]`}>{value}</p>
        </div>
    );
}
