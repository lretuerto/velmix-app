import type { ReactNode } from 'react';
import { zodResolver } from '@hookform/resolvers/zod';
import { useForm } from 'react-hook-form';
import {
    cashMovementCreateSchema,
    defaultCashMovementCreateValues,
    type CashMovementCreateFormData,
} from '@/modules/cash/sessions/schema';

interface ManualCashMovementFormProps {
    isPending: boolean;
    errorMessage: string | null;
    onSubmit: (values: CashMovementCreateFormData) => Promise<boolean>;
}

export function ManualCashMovementForm({ isPending, errorMessage, onSubmit }: ManualCashMovementFormProps) {
    const {
        register,
        handleSubmit,
        reset,
        formState: { errors },
    } = useForm<CashMovementCreateFormData>({
        resolver: zodResolver(cashMovementCreateSchema),
        defaultValues: defaultCashMovementCreateValues,
    });

    return (
        <form
            className="space-y-4"
            onSubmit={handleSubmit(async (values) => {
                const succeeded = await onSubmit(values);

                if (succeeded) {
                    reset(defaultCashMovementCreateValues);
                }
            })}
        >
            <div className="grid gap-4 md:grid-cols-2">
                <Field label="Tipo" htmlFor="cash-movement-type" error={errors.type?.message}>
                    <select
                        id="cash-movement-type"
                        className="rounded-[var(--velmix-radius-md)] border border-[var(--velmix-border-strong)] bg-white px-3 py-2 text-sm outline-none transition focus:border-[var(--velmix-brand)]"
                        {...register('type')}
                    >
                        <option value="manual_in">manual_in</option>
                        <option value="manual_out">manual_out</option>
                    </select>
                </Field>

                <Field label="Monto" htmlFor="cash-movement-amount" error={errors.amount?.message}>
                    <input
                        id="cash-movement-amount"
                        type="number"
                        min="0.01"
                        step="0.01"
                        className="rounded-[var(--velmix-radius-md)] border border-[var(--velmix-border-strong)] bg-white px-3 py-2 text-sm outline-none transition focus:border-[var(--velmix-brand)]"
                        placeholder="15.00"
                        {...register('amount')}
                    />
                </Field>
            </div>

            <Field label="Referencia" htmlFor="cash-movement-reference" error={errors.reference?.message}>
                <input
                    id="cash-movement-reference"
                    type="text"
                    className="rounded-[var(--velmix-radius-md)] border border-[var(--velmix-border-strong)] bg-white px-3 py-2 text-sm outline-none transition focus:border-[var(--velmix-brand)]"
                    placeholder="ING-001"
                    {...register('reference')}
                />
            </Field>

            <Field label="Notas" htmlFor="cash-movement-notes" error={errors.notes?.message}>
                <textarea
                    id="cash-movement-notes"
                    rows={3}
                    className="rounded-[var(--velmix-radius-md)] border border-[var(--velmix-border-strong)] bg-white px-3 py-2 text-sm outline-none transition focus:border-[var(--velmix-brand)]"
                    placeholder="Fondo adicional o gasto operativo"
                    {...register('notes')}
                />
            </Field>

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
                {isPending ? 'Registrando...' : 'Registrar movimiento'}
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
