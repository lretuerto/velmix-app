import { startTransition, useDeferredValue, useState } from 'react';
import { describeApiError, toApiError } from '@/core/api/errors';
import { useAppShell } from '@/core/app/hooks';
import { hasPermission } from '@/core/auth/permissions';
import { ApiErrorPanel } from '@/core/ui/feedback/ApiErrorPanel';
import { StatePanel } from '@/core/ui/feedback/StatePanel';
import { useToast } from '@/core/ui/feedback/toast';
import { CustomerForm } from '@/modules/sales/customers/components/CustomerForm';
import { CustomerStatementPanel } from '@/modules/sales/customers/components/CustomerStatementPanel';
import { CustomerTable } from '@/modules/sales/customers/components/CustomerTable';
import { useCustomerStatement } from '@/modules/sales/customers/hooks/useCustomerStatement';
import { useCreateCustomer, useUpdateCustomer } from '@/modules/sales/customers/hooks/useUpsertCustomer';
import { useCustomers } from '@/modules/sales/customers/hooks/useCustomers';
import {
    customerToFormValues,
    defaultCustomerFormValues,
    toCustomerCreatePayload,
    toCustomerUpdatePayload,
    type CustomerFormData,
} from '@/modules/sales/customers/schema';
import { PageHeader } from '@/shared/components/PageHeader';
import { formatCurrency, formatNumber } from '@/shared/utils/formatters';

export function CustomerIndexPage() {
    const boot = useAppShell();
    const toast = useToast();
    const customersQuery = useCustomers();
    const createCustomerMutation = useCreateCustomer();
    const updateCustomerMutation = useUpdateCustomer();
    const [search, setSearch] = useState('');
    const [selectedCustomerId, setSelectedCustomerId] = useState<number | null>(null);
    const [editorMode, setEditorMode] = useState<'create' | 'update'>('create');
    const [lastSavedCustomer, setLastSavedCustomer] = useState<{
        id: number;
        name: string;
        mode: 'create' | 'update';
    } | null>(null);

    const deferredSearch = useDeferredValue(search);
    const canCreateCustomer = hasPermission(boot.rbac.permissions, 'sales.customer.create');
    const canUpdateCustomer = hasPermission(boot.rbac.permissions, 'sales.customer.update');
    const customers = customersQuery.data ?? [];
    const selectedCustomer = customers.find((customer) => customer.id === selectedCustomerId) ?? null;
    const effectiveMode = editorMode === 'update' && selectedCustomer !== null ? 'update' : 'create';
    const statementQuery = useCustomerStatement({ customerId: selectedCustomerId });
    const normalizedSearch = deferredSearch.trim().toLowerCase();
    const filteredCustomers = customers.filter((customer) => {
        if (normalizedSearch === '') {
            return true;
        }

        return (
            customer.document_number.toLowerCase().includes(normalizedSearch)
            || customer.name.toLowerCase().includes(normalizedSearch)
            || customer.document_type.toLowerCase().includes(normalizedSearch)
            || (customer.email ?? '').toLowerCase().includes(normalizedSearch)
            || customer.status.toLowerCase().includes(normalizedSearch)
        );
    });

    const activeCustomers = customers.filter((customer) => customer.status === 'active').length;
    const outstandingTotal = customers.reduce((carry, customer) => carry + customer.outstanding_total, 0);
    const overdueTotal = customers.reduce((carry, customer) => carry + customer.overdue_total, 0);
    const customersWithCredit = customers.filter((customer) => customer.credit_limit !== null).length;
    const isInitialLoading = customersQuery.isLoading && customers.length === 0;
    const formValues = selectedCustomer !== null && effectiveMode === 'update'
        ? customerToFormValues(selectedCustomer)
        : defaultCustomerFormValues;
    const formKey = effectiveMode === 'update' && selectedCustomer !== null
        ? `update-${selectedCustomer.id}`
        : 'create';
    const currentMutation = effectiveMode === 'update' ? updateCustomerMutation : createCustomerMutation;

    const handleRefresh = async () => {
        await Promise.all([
            customersQuery.refetch(),
            selectedCustomerId !== null ? statementQuery.refetch() : Promise.resolve(),
        ]);
    };

    const handleCreateMode = () => {
        startTransition(() => {
            setEditorMode('create');
            setLastSavedCustomer(null);
        });
    };

    const handleSelectCustomer = (customerId: number) => {
        startTransition(() => {
            setSelectedCustomerId(customerId);
            setLastSavedCustomer(null);
        });
    };

    const handleEditCustomer = (customerId: number) => {
        startTransition(() => {
            setSelectedCustomerId(customerId);
            setEditorMode('update');
            setLastSavedCustomer(null);
        });
    };

    const handleSubmit = async (values: CustomerFormData) => {
        createCustomerMutation.reset();
        updateCustomerMutation.reset();
        setLastSavedCustomer(null);

        if (effectiveMode === 'update' && selectedCustomer !== null) {
            try {
                const updated = await updateCustomerMutation.mutateAsync({
                    customerId: selectedCustomer.id,
                    payload: toCustomerUpdatePayload(values),
                });

                setLastSavedCustomer({
                    id: updated.id,
                    name: updated.name,
                    mode: 'update',
                });
                toast.success({
                    title: 'Cliente actualizado',
                    description: `La politica comercial de ${updated.name} se actualizo correctamente.`,
                });
            } catch (error) {
                toast.danger({
                    title: 'No pudimos actualizar el cliente',
                    description: describeApiError(error),
                });

                // React Query mutation state already carries the actionable error.
            }

            return;
        }

        try {
            const created = await createCustomerMutation.mutateAsync(toCustomerCreatePayload(values));

            startTransition(() => {
                setSelectedCustomerId(created.id);
                setEditorMode('update');
            });
            setLastSavedCustomer({
                id: created.id,
                name: created.name,
                mode: 'create',
            });
            toast.success({
                title: 'Cliente creado',
                description: `El cliente ${created.name} ya esta disponible para ventas y cobranza.`,
            });
        } catch (error) {
            toast.danger({
                title: 'No pudimos crear el cliente',
                description: describeApiError(error),
            });

            // React Query mutation state already carries the actionable error.
        }
    };

    const canUseCurrentForm =
        (effectiveMode === 'create' && canCreateCustomer)
        || (effectiveMode === 'update' && canUpdateCustomer);

    const formErrorMessage = currentMutation.isError ? describeApiError(currentMutation.error) : null;
    const formFieldErrors = currentMutation.isError ? toApiError(currentMutation.error).validationErrors : {};
    const formSuccessMessage =
        lastSavedCustomer !== null
            ? lastSavedCustomer.mode === 'create'
                ? `Cliente ${lastSavedCustomer.name} creado correctamente.`
                : `Cliente ${lastSavedCustomer.name} actualizado correctamente.`
            : null;

    return (
        <div className="space-y-6">
            <PageHeader
                eyebrow="Sales"
                title="Clientes"
                description="El modulo ya consulta el maestro comercial real, permite alta y actualizacion administrativa, y abre el estado de cuenta del cliente con receivables, pagos y follow-ups."
                actions={
                    <div className="flex flex-wrap gap-3">
                        {canCreateCustomer && (
                            <button
                                type="button"
                                className="rounded-[var(--velmix-radius-md)] border border-[var(--velmix-border-strong)] bg-[var(--velmix-panel-strong)] px-4 py-2 text-sm font-semibold transition hover:border-[var(--velmix-brand)]"
                                onClick={handleCreateMode}
                                disabled={effectiveMode === 'create'}
                            >
                                Nuevo cliente
                            </button>
                        )}
                        <button
                            type="button"
                            className="rounded-[var(--velmix-radius-md)] border border-[var(--velmix-border-strong)] bg-[var(--velmix-panel-strong)] px-4 py-2 text-sm font-semibold transition hover:border-[var(--velmix-brand)] disabled:cursor-not-allowed disabled:opacity-60"
                            onClick={() => {
                                void handleRefresh();
                            }}
                            disabled={customersQuery.isFetching || statementQuery.isFetching}
                        >
                            {customersQuery.isFetching || statementQuery.isFetching ? 'Refrescando...' : 'Refrescar modulo'}
                        </button>
                    </div>
                }
            />

            <section className="grid gap-4 xl:grid-cols-4 md:grid-cols-2">
                <MetricCard label="Clientes" value={formatNumber(customers.length)} help="Total de clientes visibles en el tenant activo." />
                <MetricCard label="Activos" value={formatNumber(activeCustomers)} help="Clientes con estado activo en el maestro comercial." />
                <MetricCard label="Saldo abierto" value={formatCurrency(outstandingTotal)} help="Exposicion agregada de cartera del tenant actual." />
                <MetricCard label="Saldo vencido" value={formatCurrency(overdueTotal)} help="Monto vencido que requiere atencion comercial o cobranza." />
            </section>

            {isInitialLoading && (
                <StatePanel
                    tone="neutral"
                    title="Cargando maestro de clientes"
                    description="Estamos sincronizando cartera, credito disponible y politica comercial antes de habilitar busqueda o edicion."
                />
            )}

            {customersQuery.isError && (
                <ApiErrorPanel
                    title="No pudimos cargar el maestro de clientes"
                    error={customersQuery.error}
                    retryLabel="Refrescar modulo"
                    onRetry={() => {
                        void handleRefresh();
                    }}
                />
            )}

            <section className="grid gap-6 xl:grid-cols-[minmax(0,1.15fr)_minmax(360px,0.85fr)]">
                <article className="rounded-[var(--velmix-radius-xl)] border border-[var(--velmix-border)] bg-[var(--velmix-panel)] p-6 shadow-[var(--velmix-shadow)]">
                    <div className="flex flex-col gap-4 md:flex-row md:items-end md:justify-between">
                        <div>
                            <p className="text-xs font-semibold uppercase tracking-[0.18em] text-[var(--velmix-brand)]">
                                Maestro comercial
                            </p>
                            <h2 className="mt-2 text-2xl font-semibold">Listado de clientes</h2>
                            <p className="mt-2 text-sm leading-6 text-[var(--velmix-muted)]">
                                Filtro local sobre documento, nombre, correo y estado. Desde aqui abrimos cuenta comercial o pasamos a modo edicion.
                            </p>
                        </div>

                        <div className="grid gap-2">
                            <label className="text-xs font-semibold uppercase tracking-[0.16em] text-[var(--velmix-muted)]" htmlFor="customer-search">
                                Buscar
                            </label>
                            <input
                                id="customer-search"
                                type="text"
                                value={search}
                                onChange={(event) => setSearch(event.target.value)}
                                className="velmix-input px-3 py-2 text-sm"
                                placeholder="Documento, nombre, correo o estado"
                            />
                        </div>
                    </div>

                    <div className="mt-5 rounded-[var(--velmix-radius-lg)] border border-[var(--velmix-border)] bg-[var(--velmix-panel-strong)] p-4">
                        <CustomerTable
                            customers={filteredCustomers}
                            isFetching={customersQuery.isFetching}
                            selectedCustomerId={selectedCustomerId}
                            canUpdateCustomer={canUpdateCustomer}
                            onSelectCustomer={handleSelectCustomer}
                            onEditCustomer={handleEditCustomer}
                        />
                    </div>
                </article>

                <article className="rounded-[var(--velmix-radius-xl)] border border-[var(--velmix-border)] bg-[var(--velmix-panel)] p-6 shadow-[var(--velmix-shadow)]">
                    <p className="text-xs font-semibold uppercase tracking-[0.18em] text-[var(--velmix-brand)]">
                        Workspace comercial
                    </p>
                    <h2 className="mt-2 text-2xl font-semibold">
                        {effectiveMode === 'create' ? 'Alta de cliente' : 'Actualizar cliente'}
                    </h2>
                    <p className="mt-2 text-sm leading-6 text-[var(--velmix-muted)]">
                        {effectiveMode === 'create'
                            ? 'Creamos clientes con politica de credito inicial y luego abrimos su estado de cuenta desde el mismo flujo.'
                            : 'Editamos politica de credito, contacto y estado administrativo sin salir del modulo.'}
                    </p>

                    <div className="mt-5 rounded-[var(--velmix-radius-lg)] border border-[var(--velmix-border)] bg-[var(--velmix-panel-strong)] p-4">
                        <div className="mb-4 grid gap-3 sm:grid-cols-2">
                            <MiniMetric
                                label="Clientes con credito"
                                value={formatNumber(customersWithCredit)}
                            />
                            <MiniMetric
                                label="Cliente seleccionado"
                                value={selectedCustomer !== null ? selectedCustomer.name : 'Ninguno'}
                            />
                        </div>

                        {canUseCurrentForm ? (
                            <CustomerForm
                                key={formKey}
                                mode={effectiveMode}
                                initialValues={formValues}
                                isPending={currentMutation.isPending}
                                errorMessage={formErrorMessage}
                                fieldErrors={formFieldErrors}
                                successMessage={formSuccessMessage}
                                onSubmit={handleSubmit}
                                onCancelEdit={effectiveMode === 'update' ? handleCreateMode : undefined}
                            />
                        ) : (
                            <StatePanel
                                tone="warning"
                                title="Acceso de solo lectura"
                                description={
                                    effectiveMode === 'create'
                                        ? 'El usuario actual puede consultar clientes, pero no tiene el permiso `sales.customer.create` para registrar nuevos clientes.'
                                        : 'El usuario actual puede revisar la cuenta comercial, pero no tiene el permiso `sales.customer.update` para modificar el cliente seleccionado.'
                                }
                            />
                        )}
                    </div>
                </article>
            </section>

            <CustomerStatementPanel
                customer={selectedCustomer}
                statement={statementQuery.data}
                isLoading={statementQuery.isLoading || statementQuery.isFetching}
                isError={statementQuery.isError}
                errorMessage={statementQuery.isError ? describeApiError(statementQuery.error) : null}
                onRefresh={() => {
                    void statementQuery.refetch();
                }}
            />
        </div>
    );
}

interface MetricCardProps {
    label: string;
    value: string;
    help: string;
}

function MetricCard({ label, value, help }: MetricCardProps) {
    return (
        <article className="velmix-metric-card p-5">
            <p className="velmix-kicker text-[var(--velmix-muted)]">{label}</p>
            <p className="mt-2 text-3xl font-black tracking-[-0.06em]">{value}</p>
            <p className="mt-3 text-sm leading-6 text-[var(--velmix-muted)]">{help}</p>
        </article>
    );
}

interface MiniMetricProps {
    label: string;
    value: string;
}

function MiniMetric({ label, value }: MiniMetricProps) {
    return (
        <div className="velmix-card-strong px-3 py-3">
            <p className="text-[10px] font-black uppercase tracking-[0.16em] text-[var(--velmix-muted)]">{label}</p>
            <p className="mt-1 text-sm font-black">{value}</p>
        </div>
    );
}
