import { zodResolver } from '@hookform/resolvers/zod';
import { useForm } from 'react-hook-form';
import {
    cashSessionOpenSchema,
    defaultCashSessionOpenValues,
    type CashSessionOpenFormData,
} from '@/modules/cash/sessions/schema';

interface OpenCashSessionFormProps {
    isPending: boolean;
    errorMessage: string | null;
    onSubmit: (values: CashSessionOpenFormData) => Promise<boolean>;
}

export function OpenCashSessionForm({ isPending, errorMessage, onSubmit }: OpenCashSessionFormProps) {
    const {
        register,
        handleSubmit,
        reset,
        formState: { errors },
    } = useForm<CashSessionOpenFormData>({
        resolver: zodResolver(cashSessionOpenSchema),
        defaultValues: defaultCashSessionOpenValues,
    });

    return (
        <form
            className="space-y-4"
            onSubmit={handleSubmit(async (values) => {
                const succeeded = await onSubmit(values);

                if (succeeded) {
                    reset(defaultCashSessionOpenValues);
                }
            })}
        >
            <div className="grid gap-2">
                <label className="text-sm font-semibold text-[var(--velmix-ink)]" htmlFor="cash-opening-amount">
                    Monto de apertura
                </label>
                <input
                    id="cash-opening-amount"
                    type="number"
                    min="0"
                    step="0.01"
                    className="rounded-[var(--velmix-radius-md)] border border-[var(--velmix-border-strong)] bg-white px-3 py-2 text-sm outline-none transition focus:border-[var(--velmix-brand)]"
                    placeholder="100.00"
                    {...register('opening_amount')}
                />
                {errors.opening_amount !== undefined && (
                    <p className="text-sm text-rose-700">{errors.opening_amount.message}</p>
                )}
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
                {isPending ? 'Abriendo...' : 'Abrir caja'}
            </button>
        </form>
    );
}
