export interface InventoryProduct {
    id: number;
    tenant_id: number;
    sku: string;
    name: string;
    status: string;
    is_controlled: boolean;
    last_cost: number;
    average_cost: number;
}

export interface InventoryProductCreatePayload {
    sku: string;
    name: string;
    is_controlled: boolean;
}
