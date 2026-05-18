import { useMutation, useQueryClient } from '@tanstack/react-query';
import { useAppShell } from '@/core/app/hooks';
import { createCustomer, updateCustomer } from '@/modules/sales/customers/api/customers';
import type {
    SalesCustomer,
    SalesCustomerSnapshot,
    SalesCustomerCreatePayload,
    SalesCustomerUpdatePayload,
} from '@/modules/sales/customers/types';

export function useCreateCustomer() {
    const boot = useAppShell();
    const queryClient = useQueryClient();

    return useMutation({
        mutationFn: (payload: SalesCustomerCreatePayload) => createCustomer(payload),
        onSuccess: (customer: SalesCustomerSnapshot) => {
            const queryKey = ['sales-customers', boot.tenant.selected?.id ?? 0];

            void queryClient.invalidateQueries({ queryKey });
            queryClient.setQueryData<SalesCustomer[] | undefined>(queryKey, (current) => {
                const hydratedCustomer = hydrateCustomerSnapshot(customer);

                if (current === undefined) {
                    return [hydratedCustomer];
                }

                const withoutDuplicate = current.filter((item) => item.id !== customer.id);

                return [...withoutDuplicate, hydratedCustomer].sort((left, right) => left.name.localeCompare(right.name));
            });
        },
    });
}

export function useUpdateCustomer() {
    const boot = useAppShell();
    const queryClient = useQueryClient();

    return useMutation({
        mutationFn: ({ customerId, payload }: { customerId: number; payload: SalesCustomerUpdatePayload }) =>
            updateCustomer(customerId, payload),
        onSuccess: (customer: SalesCustomerSnapshot) => {
            const tenantId = boot.tenant.selected?.id ?? 0;
            const customersKey = ['sales-customers', tenantId];
            const statementKey = ['sales-customers-statement', tenantId, customer.id];

            void queryClient.invalidateQueries({ queryKey: customersKey });
            void queryClient.invalidateQueries({ queryKey: statementKey });

            queryClient.setQueryData<SalesCustomer[] | undefined>(customersKey, (current) => {
                if (current === undefined) {
                    return [hydrateCustomerSnapshot(customer)];
                }

                return current
                    .map((item) => (item.id === customer.id ? { ...item, ...customer } : item))
                    .sort((left, right) => left.name.localeCompare(right.name));
            });
        },
    });
}

function hydrateCustomerSnapshot(customer: SalesCustomerSnapshot): SalesCustomer {
    return {
        ...customer,
        outstanding_total: 0,
        overdue_total: 0,
        available_credit: customer.credit_limit,
        credit_utilization_pct: customer.credit_limit !== null && customer.credit_limit > 0 ? 0 : null,
    };
}
