import { zodResolver } from '@hookform/resolvers/zod';
import { useForm } from 'react-hook-form';
import type { ProductCreateFormData } from '@/modules/inventory/products/schema';
import { productCreateSchema } from '@/modules/inventory/products/schema';

interface ProductCreateFormProps {
    isPending: boolean;
    errorMessage: string | null;
    fieldErrors?: Partial<Record<keyof ProductCreateFormData, string[]>>;
    successMessage: string | null;
    onSubmit: (values: ProductCreateFormData) => Promise<boolean>;
}

export function ProductCreateForm({
    isPending,
    errorMessage,
    fieldErrors = {},
    successMessage,
    onSubmit,
}: ProductCreateFormProps) {
    const {
        register,
        handleSubmit,
        reset,
        formState: { errors },
    } = useForm<ProductCreateFormData>({
        resolver: zodResolver(productCreateSchema),
        defaultValues: {
            sku: '',
            name: '',
            is_controlled: false,
        },
    });

    return (
        <form
            className="space-y-4"
            onSubmit={handleSubmit(async (values) => {
                const succeeded = await onSubmit(values);

                if (succeeded) {
                    reset({
                        sku: '',
                        name: '',
                        is_controlled: false,
                    });
                }
            })}
        >
            <div className="grid gap-2">
                <label className="text-sm font-semibold text-[var(--velmix-ink)]" htmlFor="product-sku">
                    SKU
                </label>
                <input
                    id="product-sku"
                    type="text"
                    className="rounded-[var(--velmix-radius-md)] border border-[var(--velmix-border-strong)] bg-white px-3 py-2 text-sm outline-none transition focus:border-[var(--velmix-brand)]"
                    placeholder="PARA-500"
                    {...register('sku')}
                />
                <FieldError message={errors.sku?.message} serverMessages={fieldErrors.sku} />
            </div>

            <div className="grid gap-2">
                <label className="text-sm font-semibold text-[var(--velmix-ink)]" htmlFor="product-name">
                    Nombre
                </label>
                <input
                    id="product-name"
                    type="text"
                    className="rounded-[var(--velmix-radius-md)] border border-[var(--velmix-border-strong)] bg-white px-3 py-2 text-sm outline-none transition focus:border-[var(--velmix-brand)]"
                    placeholder="Paracetamol 500mg"
                    {...register('name')}
                />
                <FieldError message={errors.name?.message} serverMessages={fieldErrors.name} />
            </div>

            <label className="flex items-start gap-3 rounded-[var(--velmix-radius-md)] border border-[var(--velmix-border)] bg-[var(--velmix-panel-strong)] px-3 py-3">
                <input
                    type="checkbox"
                    className="mt-1 h-4 w-4 rounded border-[var(--velmix-border-strong)] text-[var(--velmix-brand)]"
                    {...register('is_controlled')}
                />
                <span className="text-sm leading-6 text-[var(--velmix-muted)]">
                    Marcar como producto controlado si requiere seguimiento especial de inventario o custodia.
                </span>
            </label>

            {errorMessage !== null && (
                <p className="rounded-[var(--velmix-radius-md)] border border-rose-200 bg-rose-50 px-3 py-2 text-sm text-rose-900">
                    {errorMessage}
                </p>
            )}

            {successMessage !== null && (
                <p className="rounded-[var(--velmix-radius-md)] border border-emerald-200 bg-emerald-50 px-3 py-2 text-sm text-emerald-900">
                    {successMessage}
                </p>
            )}

            <button
                type="submit"
                className="inline-flex items-center rounded-[var(--velmix-radius-md)] bg-[var(--velmix-brand)] px-4 py-2 text-sm font-semibold text-white transition hover:opacity-90 disabled:cursor-not-allowed disabled:opacity-60"
                disabled={isPending}
            >
                {isPending ? 'Creando...' : 'Crear producto'}
            </button>
        </form>
    );
}

interface FieldErrorProps {
    message?: string;
    serverMessages?: string[];
}

function FieldError({ message, serverMessages = [] }: FieldErrorProps) {
    const resolvedMessage = message ?? serverMessages[0];

    return resolvedMessage !== undefined ? <p className="text-sm text-rose-700">{resolvedMessage}</p> : null;
}
