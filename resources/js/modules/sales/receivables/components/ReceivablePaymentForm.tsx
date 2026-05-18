import { zodResolver } from '@hookform/resolvers/zod';
import type { ReactNode } from 'react';
import { useForm } from 'react-hook-form';
import {
    defaultReceivablePaymentValues,
    receivablePaymentSchema,
    type ReceivablePaymentFormData,
} from '@/modules/sales/receivables/schema';

interface ReceivablePaymentFormProps {
    isPending: boolean;
    errorMessage: string | null;
    onSubmit: (values: ReceivablePaymentFormData) => Promise<boolean>;
}

export function ReceivablePaymentForm({ isPending, errorMessage, onSubmit }: ReceivablePaymentFormProps) {
    const {
        register,
        handleSubmit,
        reset,
        formState: { errors },
    } = useForm<ReceivablePaymentFormData>({
        resolver: zodResolver(receivablePaymentSchema),
        defaultValues: defaultReceivablePaymentValues,
    });

    return (
        <form
            className="space-y-4"
            onSubmit={handleSubmit(async (values) => {
                const succeeded = await onSubmit(values);

                if (succeeded) {
                    reset(defaultReceivablePaymentValues);
                }
            })}
        >
            <Field label="Monto" htmlFor="receivable-payment-amount" error={errors.amount?.message}>
                <input
                    id="receivable-payment-amount"
                    type="number"
                    step="0.01"
                    min="0.01"
                    className="rounded-[var(--velmix-radius-md)] border border-[var(--velmix-border-strong)] bg-white px-3 py-2 text-sm outline-none transition focus:border-[var(--velmix-brand)]"
                    placeholder="8.00"
                    {...register('amount')}
                />
            </Field>

            <Field label="Metodo" htmlFor="receivable-payment-method" error={errors.payment_method?.message}>
                <select
                    id="receivable-payment-method"
                    className="rounded-[var(--velmix-radius-md)] border border-[var(--velmix-border-strong)] bg-white px-3 py-2 text-sm outline-none transition focus:border-[var(--velmix-brand)]"
                    {...register('payment_method')}
                >
                    <option value="cash">cash</option>
                    <option value="card">card</option>
                    <option value="transfer">transfer</option>
                    <option value="bank_transfer">bank_transfer</option>
                </select>
            </Field>

            <Field label="Referencia" htmlFor="receivable-payment-reference" error={errors.reference?.message}>
                <input
                    id="receivable-payment-reference"
                    type="text"
                    className="rounded-[var(--velmix-radius-md)] border border-[var(--velmix-border-strong)] bg-white px-3 py-2 text-sm outline-none transition focus:border-[var(--velmix-brand)]"
                    placeholder="COBRO-001"
                    {...register('reference')}
                />
            </Field>

            <p className="text-xs leading-5 text-[var(--velmix-muted)]">
                Si eliges `cash`, el backend exigira una caja abierta en el tenant actual.
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
                {isPending ? 'Registrando...' : 'Registrar cobranza'}
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
