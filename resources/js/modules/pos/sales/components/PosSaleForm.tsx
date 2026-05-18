import { startTransition, useDeferredValue, useEffect, useState, type ReactNode } from 'react';
import { zodResolver } from '@hookform/resolvers/zod';
import { useFieldArray, useForm } from 'react-hook-form';
import type { InventoryProduct } from '@/modules/inventory/products/types';
import type { SalesCustomer } from '@/modules/sales/customers/types';
import {
    defaultPosSaleCreateValues,
    defaultPosSaleLineValues,
    posSaleCreateSchema,
    type PosSaleCreateFormData,
} from '@/modules/pos/sales/schema';

interface PosSaleFormProps {
    products: InventoryProduct[];
    customers: SalesCustomer[];
    isPending: boolean;
    errorMessage: string | null;
    submitLabel?: string;
    onSubmit: (values: PosSaleCreateFormData) => Promise<boolean>;
}

export function PosSaleForm({
    products,
    customers,
    isPending,
    errorMessage,
    submitLabel = 'Cotizar venta POS',
    onSubmit,
}: PosSaleFormProps) {
    const {
        control,
        register,
        watch,
        getValues,
        handleSubmit,
        reset,
        setValue,
        formState: { errors },
    } = useForm<PosSaleCreateFormData>({
        resolver: zodResolver(posSaleCreateSchema),
        defaultValues: defaultPosSaleCreateValues,
    });
    const [productSearch, setProductSearch] = useState('');
    const deferredProductSearch = useDeferredValue(productSearch);
    const paymentMethod = watch('payment_method');
    const lines = watch('items');
    const { fields, append, remove } = useFieldArray({
        control,
        name: 'items',
    });
    const normalizedProductSearch = deferredProductSearch.trim().toLowerCase();
    const quickProducts = (normalizedProductSearch === ''
        ? products
        : products.filter((product) => (
            product.sku.toLowerCase().includes(normalizedProductSearch)
            || product.name.toLowerCase().includes(normalizedProductSearch)
        ))
    ).slice(0, 8);
    const selectedProductIds = new Set(
        lines
            .map((line) => Number.parseInt(line.product_id, 10))
            .filter((productId) => Number.isInteger(productId)),
    );

    useEffect(() => {
        if (paymentMethod !== 'credit' || customers.length !== 1 || getValues('customer_id') !== '') {
            return;
        }

        setValue('customer_id', String(customers[0].id), {
            shouldDirty: true,
            shouldValidate: true,
        });
    }, [customers, getValues, paymentMethod, setValue]);

    const handleQuickAddProduct = (product: InventoryProduct) => {
        startTransition(() => {
            const currentLines = getValues('items');
            const existingIndex = currentLines.findIndex((line) => Number.parseInt(line.product_id, 10) === product.id);

            if (existingIndex >= 0) {
                const currentQuantity = Number.parseInt(currentLines[existingIndex]?.quantity ?? '1', 10);

                setValue(`items.${existingIndex}.quantity`, String(Math.max(1, currentQuantity + 1)), {
                    shouldDirty: true,
                    shouldValidate: true,
                });
                setProductSearch('');

                return;
            }

            const emptyIndex = currentLines.findIndex((line) => line.product_id.trim() === '');

            if (emptyIndex >= 0) {
                setValue(`items.${emptyIndex}.product_id`, String(product.id), {
                    shouldDirty: true,
                    shouldValidate: true,
                });
                setValue(`items.${emptyIndex}.quantity`, '1', {
                    shouldDirty: true,
                    shouldValidate: true,
                });
                setValue(`items.${emptyIndex}.prescription_code`, '', {
                    shouldDirty: true,
                    shouldValidate: true,
                });
                setValue(`items.${emptyIndex}.approval_code`, '', {
                    shouldDirty: true,
                    shouldValidate: true,
                });
            } else {
                append({
                    ...defaultPosSaleLineValues,
                    product_id: String(product.id),
                    quantity: '1',
                });
            }

            setProductSearch('');
        });
    };

    const adjustQuantity = (index: number, delta: number) => {
        const currentQuantity = Number.parseInt(getValues(`items.${index}.quantity`) || '1', 10);

        setValue(`items.${index}.quantity`, String(Math.max(1, currentQuantity + delta)), {
            shouldDirty: true,
            shouldValidate: true,
        });
    };

    return (
        <form
            className="space-y-4"
            onSubmit={handleSubmit(async (values) => {
                const succeeded = await onSubmit(values);

                if (succeeded) {
                    reset(defaultPosSaleCreateValues);
                }
            })}
        >
            <div className="grid gap-3 md:grid-cols-2">
                <Field label="Metodo de pago" htmlFor="pos-payment-method" error={errors.payment_method?.message}>
                    <select
                        id="pos-payment-method"
                        className="velmix-input px-3 py-2 text-sm font-semibold"
                        {...register('payment_method')}
                    >
                        <option value="cash">cash</option>
                        <option value="card">card</option>
                        <option value="transfer">transfer</option>
                        <option value="credit">credit</option>
                    </select>
                </Field>

                <Field label="Cliente" htmlFor="pos-customer-id" error={errors.customer_id?.message}>
                    <select
                        id="pos-customer-id"
                        className="velmix-input px-3 py-2 text-sm font-semibold disabled:opacity-60"
                        {...register('customer_id')}
                    >
                        <option value="">Selecciona cliente</option>
                        {customers.map((customer) => (
                            <option key={customer.id} value={customer.id}>
                                {customer.name} · {customer.document_number}
                            </option>
                        ))}
                    </select>
                    <p className="text-xs leading-5 text-[var(--velmix-muted)]">
                        Obligatorio para ventas a credito; opcional para cash, card y transfer.
                    </p>
                </Field>

                <Field label="Vence el" htmlFor="pos-due-at" error={errors.due_at?.message}>
                    <input
                        id="pos-due-at"
                        type="date"
                        className="velmix-input px-3 py-2 text-sm font-semibold disabled:opacity-60"
                        disabled={paymentMethod !== 'credit'}
                        {...register('due_at')}
                    />
                </Field>
            </div>

            <div className="space-y-4">
                <div className="velmix-card-strong p-4">
                    <div className="flex flex-col gap-3 md:flex-row md:items-end md:justify-between">
                        <div>
                            <p className="text-sm font-black text-[var(--velmix-ink)]">Busqueda rapida de productos</p>
                            <p className="mt-1 text-xs leading-5 text-[var(--velmix-muted)]">
                                Busca por SKU o nombre. Si el producto ya esta en el carrito, el boton suma una unidad sin duplicar la linea.
                            </p>
                        </div>
                        <input
                            type="search"
                            value={productSearch}
                            onChange={(event) => setProductSearch(event.target.value)}
                            className="velmix-input w-full px-3 py-2 text-sm md:max-w-sm"
                            placeholder="Ej. CLON, paracetamol, SKU..."
                        />
                    </div>

                    <div className="mt-4 grid gap-3 sm:grid-cols-2">
                        {quickProducts.map((product) => {
                            const isSelected = selectedProductIds.has(product.id);

                            return (
                                <button
                                    key={product.id}
                                    type="button"
                                    className={`min-h-28 rounded-[var(--velmix-radius-md)] border bg-white px-3 py-3 text-left shadow-[0_8px_20px_rgb(16_35_30_/_0.04)] transition hover:-translate-y-0.5 hover:border-[var(--velmix-brand)] hover:shadow-[0_14px_28px_rgb(16_35_30_/_0.08)] ${isSelected ? 'border-[var(--velmix-brand)] ring-4 ring-[rgb(180_91_42_/_0.1)]' : 'border-[var(--velmix-border)]'}`}
                                    onClick={() => handleQuickAddProduct(product)}
                                >
                                    <span className="flex items-start justify-between gap-3">
                                        <span>
                                            <span className="block text-sm font-semibold text-[var(--velmix-ink)]">
                                                {product.sku} · {product.name}
                                            </span>
                                            <span className="mt-1 block text-xs text-[var(--velmix-muted)]">
                                                {product.is_controlled ? 'Controlado: requiere receta o aprobacion' : 'Venta regular'}
                                            </span>
                                        </span>
                                        <span className="rounded-full bg-[var(--velmix-brand-soft)] px-2 py-1 text-xs font-black text-[var(--velmix-brand-strong)]">
                                            {isSelected ? '+1' : 'Agregar'}
                                        </span>
                                    </span>
                                </button>
                            );
                        })}

                        {quickProducts.length === 0 && (
                            <p className="rounded-[var(--velmix-radius-md)] border border-dashed border-[var(--velmix-border-strong)] px-3 py-4 text-sm text-[var(--velmix-muted)]">
                                No encontramos productos activos con esa busqueda. Ajusta el texto o revisa el catalogo de inventario.
                            </p>
                        )}
                    </div>
                </div>

                <div className="flex items-center justify-between gap-3">
                    <div>
                        <p className="text-sm font-black text-[var(--velmix-ink)]">Lineas de venta</p>
                        <p className="text-xs leading-5 text-[var(--velmix-muted)]">
                            Usamos `product_id` para venta FIFO y el precio comercial sale del quote backend. Si el producto es controlado, agrega receta o approval code.
                        </p>
                    </div>
                    <button
                        type="button"
                        className="velmix-button-secondary px-3 py-2 text-xs"
                        onClick={() => append(defaultPosSaleLineValues)}
                    >
                        Agregar linea
                    </button>
                </div>

                {fields.map((field, index) => {
                    const productId = Number.parseInt(lines[index]?.product_id ?? '', 10);
                    const product = products.find((item) => item.id === productId) ?? null;

                    return (
                        <article
                            key={field.id}
                            className="velmix-card-strong p-4"
                        >
                            <div className="mb-4 flex items-center justify-between gap-3">
                                <p className="text-sm font-black">Linea {index + 1}</p>
                                {fields.length > 1 && (
                                    <button
                                        type="button"
                                        className="rounded-[var(--velmix-radius-md)] border border-rose-200 px-3 py-2 text-xs font-black text-rose-700 transition hover:bg-rose-50"
                                        onClick={() => remove(index)}
                                    >
                                        Quitar
                                    </button>
                                )}
                            </div>

                            <div className="grid gap-3">
                                <Field
                                    label="Producto"
                                    htmlFor={`pos-line-product-${index}`}
                                    error={errors.items?.[index]?.product_id?.message}
                                >
                                    <select
                                        id={`pos-line-product-${index}`}
                                        className="velmix-input px-3 py-2 text-sm"
                                        {...register(`items.${index}.product_id`)}
                                    >
                                        <option value="">Selecciona producto</option>
                                        {products.map((item) => (
                                            <option key={item.id} value={item.id}>
                                                {item.sku} · {item.name}
                                            </option>
                                        ))}
                                    </select>
                                </Field>

                                <Field
                                    label="Cantidad"
                                    htmlFor={`pos-line-quantity-${index}`}
                                    error={errors.items?.[index]?.quantity?.message}
                                >
                                    <div className="flex rounded-[var(--velmix-radius-md)] border border-[var(--velmix-border-strong)] bg-white">
                                        <button
                                            type="button"
                                            className="px-3 text-sm font-black text-[var(--velmix-muted)] transition hover:text-[var(--velmix-brand)]"
                                            onClick={() => adjustQuantity(index, -1)}
                                            aria-label={`Restar cantidad de linea ${index + 1}`}
                                        >
                                            -
                                        </button>
                                        <input
                                            id={`pos-line-quantity-${index}`}
                                            type="number"
                                            min="1"
                                            step="1"
                                            className="min-w-0 flex-1 border-x border-[var(--velmix-border)] px-3 py-2 text-center text-sm font-semibold outline-none"
                                            {...register(`items.${index}.quantity`)}
                                        />
                                        <button
                                            type="button"
                                            className="px-3 text-sm font-black text-[var(--velmix-muted)] transition hover:text-[var(--velmix-brand)]"
                                            onClick={() => adjustQuantity(index, 1)}
                                            aria-label={`Sumar cantidad de linea ${index + 1}`}
                                        >
                                            +
                                        </button>
                                    </div>
                                </Field>
                            </div>

                            <div className="mt-4 grid gap-3">
                                <Field
                                    label="Prescription code"
                                    htmlFor={`pos-line-rx-${index}`}
                                    error={errors.items?.[index]?.prescription_code?.message}
                                >
                                    <input
                                        id={`pos-line-rx-${index}`}
                                        type="text"
                                        className="velmix-input px-3 py-2 text-sm"
                                        placeholder={product?.is_controlled ? 'RX-001' : 'Opcional'}
                                        {...register(`items.${index}.prescription_code`)}
                                    />
                                </Field>

                                <Field
                                    label="Approval code"
                                    htmlFor={`pos-line-approval-${index}`}
                                    error={errors.items?.[index]?.approval_code?.message}
                                >
                                    <input
                                        id={`pos-line-approval-${index}`}
                                        type="text"
                                        className="velmix-input px-3 py-2 text-sm"
                                        placeholder={product?.is_controlled ? 'APR-000123' : 'Opcional'}
                                        {...register(`items.${index}.approval_code`)}
                                    />
                                </Field>
                            </div>

                            {product?.is_controlled === true && (
                                <p className="mt-3 rounded-[var(--velmix-radius-md)] border border-amber-200 bg-amber-50 px-3 py-2 text-xs leading-5 text-amber-900">
                                    Producto controlado: el backend exigira `prescription_code` o `approval_code`.
                                </p>
                            )}
                        </article>
                    );
                })}
            </div>

            {errorMessage !== null && (
                <p className="rounded-[var(--velmix-radius-md)] border border-rose-200 bg-rose-50 px-3 py-2 text-sm text-rose-900">
                    {errorMessage}
                </p>
            )}

            <button
                type="submit"
                className="velmix-button-primary inline-flex items-center px-5 py-3 text-sm disabled:cursor-not-allowed disabled:opacity-60"
                disabled={isPending}
            >
                {isPending ? 'Procesando...' : submitLabel}
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
            <label className="text-xs font-black uppercase tracking-[0.08em] text-[var(--velmix-ink)]" htmlFor={htmlFor}>
                {label}
            </label>
            {children}
            {error !== undefined && <p className="text-sm text-rose-700">{error}</p>}
        </div>
    );
}
