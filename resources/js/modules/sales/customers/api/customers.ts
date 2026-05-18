import { getJson, patchJson, postJson } from '@/core/api/client';
import type {
    CustomerStatement,
    SalesCustomer,
    SalesCustomerSnapshot,
    SalesCustomerCreatePayload,
    SalesCustomerUpdatePayload,
} from '@/modules/sales/customers/types';

export async function fetchCustomers(): Promise<SalesCustomer[]> {
    return getJson<SalesCustomer[]>('/sales/customers');
}

export async function fetchCustomerStatement(customerId: number): Promise<CustomerStatement> {
    return getJson<CustomerStatement>(`/sales/customers/${customerId}/statement`);
}

export async function createCustomer(payload: SalesCustomerCreatePayload): Promise<SalesCustomerSnapshot> {
    return postJson<SalesCustomerSnapshot, SalesCustomerCreatePayload>('/sales/customers', payload);
}

export async function updateCustomer(customerId: number, payload: SalesCustomerUpdatePayload): Promise<SalesCustomerSnapshot> {
    return patchJson<SalesCustomerSnapshot, SalesCustomerUpdatePayload>(`/sales/customers/${customerId}`, payload);
}
