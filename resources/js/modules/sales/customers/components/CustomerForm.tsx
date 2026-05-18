import { zodResolver } from '@hookform/resolvers/zod';
import type { ReactNode } from 'react';
import { useForm } from 'react-hook-form';
import {
    customerFormSchema,
    defaultCustomerFormValues,
    type CustomerFormData,
} from '@/modules/sales/customers/schema';

interface CustomerFormProps {
    mode: 'create' | 'update';
    initialValues?: CustomerFormData;
    isPending: boolean;
    errorMessage: string | null;
    fieldErrors?: Partial<Record<keyof CustomerFormData, string[]>>;
    successMessage: string | null;
    onSubmit: (values: CustomerFormData) => Promise<void>;
    onCancelEdit?: () => void;
}

export function CustomerForm({
    mode,
    initialValues = defaultCustomerFormValues,
    isPending,
    errorMessage,
    fieldErrors = {},
    successMessage,
    onSubmit,
    onCancelEdit,
}: CustomerFormProps) {
    const {
        register,
        handleSubmit,
        formState: { errors },
    } = useForm<CustomerFormData>({
        resolver: zodResolver(customerFormSchema),
        defaultValues: initialValues,
    });

    return (
        <form className="space-y-4" onSubmit={handleSubmit(onSubmit)}>
            <div className="grid gap-4 md:grid-cols-2">
                <Field
                    label="Tipo de documento"
                    error={errors.document_type?.message ?? fieldErrors.document_type?.[0]}
                    htmlFor="customer-document-type"
                >
                    <input
                        id="customer-document-type"
                        type="text"
                        className="rounded-[var(--velmix-radius-md)] border border-[var(--velmix-border-strong)] bg-white px-3 py-2 text-sm outline-none transition focus:border-[var(--velmix-brand)]"
                        placeholder="dni"
                        {...register('document_type')}
                    />
                </Field>

                <Field
                    label="Numero de documento"
                    error={errors.document_number?.message ?? fieldErrors.document_number?.[0]}
                    htmlFor="customer-document-number"
                >
                    <input
                        id="customer-document-number"
                        type="text"
                        className="rounded-[var(--velmix-radius-md)] border border-[var(--velmix-border-strong)] bg-white px-3 py-2 text-sm outline-none transition focus:border-[var(--velmix-brand)]"
                        placeholder="12345678"
                        {...register('document_number')}
                    />
                </Field>
            </div>

            <Field label="Nombre del cliente" error={errors.name?.message ?? fieldErrors.name?.[0]} htmlFor="customer-name">
                <input
                    id="customer-name"
                    type="text"
                    className="rounded-[var(--velmix-radius-md)] border border-[var(--velmix-border-strong)] bg-white px-3 py-2 text-sm outline-none transition focus:border-[var(--velmix-brand)]"
                    placeholder="Cliente mostrador"
                    {...register('name')}
                />
            </Field>

            <div className="grid gap-4 md:grid-cols-2">
                <Field label="Telefono" error={errors.phone?.message ?? fieldErrors.phone?.[0]} htmlFor="customer-phone">
                    <input
                        id="customer-phone"
                        type="text"
                        className="rounded-[var(--velmix-radius-md)] border border-[var(--velmix-border-strong)] bg-white px-3 py-2 text-sm outline-none transition focus:border-[var(--velmix-brand)]"
                        placeholder="999111222"
                        {...register('phone')}
                    />
                </Field>

                <Field label="Correo" error={errors.email?.message ?? fieldErrors.email?.[0]} htmlFor="customer-email">
                    <input
                        id="customer-email"
                        type="email"
                        className="rounded-[var(--velmix-radius-md)] border border-[var(--velmix-border-strong)] bg-white px-3 py-2 text-sm outline-none transition focus:border-[var(--velmix-brand)]"
                        placeholder="cliente@velmix.app"
                        {...register('email')}
                    />
                </Field>
            </div>

            <div className="grid gap-4 md:grid-cols-2">
                <Field
                    label="Limite de credito"
                    error={errors.credit_limit?.message ?? fieldErrors.credit_limit?.[0]}
                    htmlFor="customer-credit-limit"
                >
                    <input
                        id="customer-credit-limit"
                        type="number"
                        step="0.01"
                        min="0"
                        className="rounded-[var(--velmix-radius-md)] border border-[var(--velmix-border-strong)] bg-white px-3 py-2 text-sm outline-none transition focus:border-[var(--velmix-brand)]"
                        placeholder="150.00"
                        {...register('credit_limit')}
                    />
                </Field>

                <Field
                    label="Dias de credito"
                    error={errors.credit_days?.message ?? fieldErrors.credit_days?.[0]}
                    htmlFor="customer-credit-days"
                >
                    <input
                        id="customer-credit-days"
                        type="number"
                        min="0"
                        step="1"
                        className="rounded-[var(--velmix-radius-md)] border border-[var(--velmix-border-strong)] bg-white px-3 py-2 text-sm outline-none transition focus:border-[var(--velmix-brand)]"
                        placeholder="15"
                        {...register('credit_days')}
                    />
                </Field>
            </div>

            <label className="flex items-start gap-3 rounded-[var(--velmix-radius-md)] border border-[var(--velmix-border)] bg-[var(--velmix-panel-strong)] px-3 py-3">
                <input
                    type="checkbox"
                    className="mt-1 h-4 w-4 rounded border-[var(--velmix-border-strong)] text-[var(--velmix-brand)]"
                    {...register('block_on_overdue')}
                />
                <span className="text-sm leading-6 text-[var(--velmix-muted)]">
                    Bloquear ventas a credito si el cliente tiene documentos vencidos.
                </span>
            </label>

            {mode === 'update' && (
                <Field label="Estado" error={errors.status?.message ?? fieldErrors.status?.[0]} htmlFor="customer-status">
                    <select
                        id="customer-status"
                        className="rounded-[var(--velmix-radius-md)] border border-[var(--velmix-border-strong)] bg-white px-3 py-2 text-sm outline-none transition focus:border-[var(--velmix-brand)]"
                        {...register('status')}
                    >
                        <option value="active">Activo</option>
                        <option value="inactive">Inactivo</option>
                    </select>
                </Field>
            )}

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

            <div className="flex flex-wrap gap-3">
                <button
                    type="submit"
                    className="inline-flex items-center rounded-[var(--velmix-radius-md)] bg-[var(--velmix-brand)] px-4 py-2 text-sm font-semibold text-white transition hover:opacity-90 disabled:cursor-not-allowed disabled:opacity-60"
                    disabled={isPending}
                >
                    {isPending ? (mode === 'create' ? 'Creando...' : 'Guardando...') : mode === 'create' ? 'Crear cliente' : 'Guardar cambios'}
                </button>

                {mode === 'update' && onCancelEdit !== undefined && (
                    <button
                        type="button"
                        className="rounded-[var(--velmix-radius-md)] border border-[var(--velmix-border-strong)] px-4 py-2 text-sm font-semibold transition hover:border-[var(--velmix-brand)]"
                        onClick={onCancelEdit}
                    >
                        Cancelar edicion
                    </button>
                )}
            </div>
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
