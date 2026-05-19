import { zodResolver } from '@hookform/resolvers/zod';
import type { ReactNode } from 'react';
import { useFieldArray, useForm } from 'react-hook-form';
import {
    cashSessionCloseSchema,
    defaultCashDenominationValues,
    defaultCashSessionCloseValues,
    type CashSessionCloseFormData,
} from '@/modules/cash/sessions/schema';

interface CloseCashSessionFormProps {
    isPending: boolean;
    errorMessage: string | null;
    onSubmit: (values: CashSessionCloseFormData) => Promise<boolean>;
}

export function CloseCashSessionForm({ isPending, errorMessage, onSubmit }: CloseCashSessionFormProps) {
    const {
        control,
        register,
        handleSubmit,
        reset,
        formState: { errors },
    } = useForm<CashSessionCloseFormData>({
        resolver: zodResolver(cashSessionCloseSchema),
        defaultValues: defaultCashSessionCloseValues,
    });
    const { fields, append, remove } = useFieldArray({
        control,
        name: 'denominations',
    });

    return (
        <form
            className="space-y-4"
            onSubmit={handleSubmit(async (values) => {
                const succeeded = await onSubmit(values);

                if (succeeded) {
                    reset(defaultCashSessionCloseValues);
                }
            })}
        >
            <Field label="Monto contado" htmlFor="cash-counted-amount" error={errors.counted_amount?.message}>
                <input
                    id="cash-counted-amount"
                    type="number"
                    min="0"
                    step="0.01"
                    className="rounded-[var(--velmix-radius-md)] border border-[var(--velmix-border-strong)] bg-white px-3 py-2 text-sm outline-none transition focus:border-[var(--velmix-brand)]"
                    placeholder="135.50"
                    {...register('counted_amount')}
                />
            </Field>

            <div className="space-y-3">
                <div className="flex items-center justify-between gap-3">
                    <div>
                        <p className="text-sm font-semibold text-[var(--velmix-ink)]">Denominaciones</p>
                        <p className="text-xs leading-5 text-[var(--velmix-muted)]">
                            Opcionales. Si las usas, deben cuadrar con el monto contado.
                        </p>
                    </div>
                    <button
                        type="button"
                        className="rounded-[var(--velmix-radius-md)] border border-[var(--velmix-border-strong)] px-3 py-2 text-xs font-semibold transition hover:border-[var(--velmix-brand)]"
                        onClick={() => append(defaultCashDenominationValues)}
                    >
                        Agregar denominacion
                    </button>
                </div>

                {fields.map((field, index) => (
                    <div key={field.id} className="grid gap-3 rounded-[var(--velmix-radius-md)] border border-[var(--velmix-border)] bg-white/80 p-3 md:grid-cols-[minmax(0,1fr)_minmax(0,1fr)_auto]">
                        <Field label="Valor" htmlFor={`cash-denomination-value-${index}`} error={errors.denominations?.[index]?.value?.message}>
                            <input
                                id={`cash-denomination-value-${index}`}
                                type="number"
                                min="0.01"
                                step="0.01"
                                className="rounded-[var(--velmix-radius-md)] border border-[var(--velmix-border-strong)] bg-white px-3 py-2 text-sm outline-none transition focus:border-[var(--velmix-brand)]"
                                placeholder="50"
                                {...register(`denominations.${index}.value`)}
                            />
                        </Field>
                        <Field label="Cantidad" htmlFor={`cash-denomination-quantity-${index}`} error={errors.denominations?.[index]?.quantity?.message}>
                            <input
                                id={`cash-denomination-quantity-${index}`}
                                type="number"
                                min="1"
                                step="1"
                                className="rounded-[var(--velmix-radius-md)] border border-[var(--velmix-border-strong)] bg-white px-3 py-2 text-sm outline-none transition focus:border-[var(--velmix-brand)]"
                                placeholder="2"
                                {...register(`denominations.${index}.quantity`)}
                            />
                        </Field>
                        <div className="flex items-end">
                            <button
                                type="button"
                                className="rounded-[var(--velmix-radius-md)] border border-rose-200 px-3 py-2 text-xs font-semibold text-rose-700 transition hover:bg-rose-50"
                                onClick={() => remove(index)}
                            >
                                Quitar
                            </button>
                        </div>
                    </div>
                ))}
            </div>

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
                {isPending ? 'Cerrando...' : 'Cerrar caja'}
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
