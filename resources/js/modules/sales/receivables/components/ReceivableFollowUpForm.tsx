import { zodResolver } from '@hookform/resolvers/zod';
import type { ReactNode } from 'react';
import { useForm } from 'react-hook-form';
import {
    defaultReceivableFollowUpValues,
    receivableFollowUpSchema,
    type ReceivableFollowUpFormData,
} from '@/modules/sales/receivables/schema';

interface ReceivableFollowUpFormProps {
    isPending: boolean;
    errorMessage: string | null;
    onSubmit: (values: ReceivableFollowUpFormData) => Promise<boolean>;
}

export function ReceivableFollowUpForm({ isPending, errorMessage, onSubmit }: ReceivableFollowUpFormProps) {
    const {
        register,
        watch,
        handleSubmit,
        reset,
        formState: { errors },
    } = useForm<ReceivableFollowUpFormData>({
        resolver: zodResolver(receivableFollowUpSchema),
        defaultValues: defaultReceivableFollowUpValues,
    });
    const followUpType = watch('type');

    return (
        <form
            className="space-y-4"
            onSubmit={handleSubmit(async (values) => {
                const succeeded = await onSubmit(values);

                if (succeeded) {
                    reset(defaultReceivableFollowUpValues);
                }
            })}
        >
            <Field label="Tipo" htmlFor="receivable-follow-up-type" error={errors.type?.message}>
                <select
                    id="receivable-follow-up-type"
                    className="rounded-[var(--velmix-radius-md)] border border-[var(--velmix-border-strong)] bg-white px-3 py-2 text-sm outline-none transition focus:border-[var(--velmix-brand)]"
                    {...register('type')}
                >
                    <option value="note">note</option>
                    <option value="promise">promise</option>
                </select>
            </Field>

            <Field label="Nota" htmlFor="receivable-follow-up-note" error={errors.note?.message}>
                <textarea
                    id="receivable-follow-up-note"
                    rows={4}
                    className="rounded-[var(--velmix-radius-md)] border border-[var(--velmix-border-strong)] bg-white px-3 py-2 text-sm outline-none transition focus:border-[var(--velmix-brand)]"
                    placeholder="Cliente promete cancelar el viernes"
                    {...register('note')}
                />
            </Field>

            <div className="grid gap-4 md:grid-cols-2">
                <Field label="Monto prometido" htmlFor="receivable-follow-up-amount" error={errors.promised_amount?.message}>
                    <input
                        id="receivable-follow-up-amount"
                        type="number"
                        step="0.01"
                        min="0.01"
                        className="rounded-[var(--velmix-radius-md)] border border-[var(--velmix-border-strong)] bg-white px-3 py-2 text-sm outline-none transition focus:border-[var(--velmix-brand)]"
                        placeholder="9.00"
                        {...register('promised_amount')}
                    />
                </Field>

                <Field label="Fecha comprometida" htmlFor="receivable-follow-up-date" error={errors.promised_at?.message}>
                    <input
                        id="receivable-follow-up-date"
                        type="date"
                        className="rounded-[var(--velmix-radius-md)] border border-[var(--velmix-border-strong)] bg-white px-3 py-2 text-sm outline-none transition focus:border-[var(--velmix-brand)]"
                        {...register('promised_at')}
                    />
                </Field>
            </div>

            <p className="text-xs leading-5 text-[var(--velmix-muted)]">
                {followUpType === 'promise'
                    ? 'Las promesas exigen fecha comprometida y pueden registrar monto previsto.'
                    : 'Las notas dejan trazabilidad operativa sin comprometer fecha o monto.'}
            </p>

            {errorMessage !== null && (
                <p className="rounded-[var(--velmix-radius-md)] border border-rose-200 bg-rose-50 px-3 py-2 text-sm text-rose-900">
                    {errorMessage}
                </p>
            )}

            <button
                type="submit"
                className="inline-flex items-center rounded-[var(--velmix-radius-md)] bg-[var(--velmix-brand)] px-4 py-2 text-sm font-semibold text-white transition hover:opacity-90 disabled:cursor-not-allowed disabled:opacity-60"
                disabled={isPending}
            >
                {isPending ? 'Registrando...' : 'Registrar follow-up'}
            </button>
        </form>
    );
}

interface FieldProps {
    label: string;
    htmlFor: string;
    error?: string;
    children: ReactNode;
}

function Field({ label, htmlFor, error, children }: FieldProps) {
    return (
        <div className="grid gap-2">
            <label className="text-sm font-semibold text-[var(--velmix-ink)]" htmlFor={htmlFor}>
                {label}
            </label>
            {children}
            {error !== undefined && <p className="text-sm text-rose-700">{error}</p>}
        </div>
    );
}
