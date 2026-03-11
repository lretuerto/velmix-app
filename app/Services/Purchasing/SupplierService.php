<?php

namespace App\Services\Purchasing;

use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\HttpException;

class SupplierService
{
    public function list(int $tenantId): array
    {
        if ($tenantId <= 0) {
            throw new HttpException(403, 'Tenant context is required.');
        }

        return DB::table('suppliers')
            ->where('tenant_id', $tenantId)
            ->orderBy('name')
            ->get(['id', 'tenant_id', 'tax_id', 'name', 'status'])
            ->map(fn (object $supplier) => [
                'id' => $supplier->id,
                'tenant_id' => $supplier->tenant_id,
                'tax_id' => $supplier->tax_id,
                'name' => $supplier->name,
                'status' => $supplier->status,
            ])
            ->all();
    }

    public function create(int $tenantId, string $taxId, string $name): array
    {
        if ($tenantId <= 0) {
            throw new HttpException(403, 'Tenant context is required.');
        }

        $exists = DB::table('suppliers')
            ->where('tenant_id', $tenantId)
            ->where('tax_id', $taxId)
            ->exists();

        if ($exists) {
            throw new HttpException(422, 'Supplier tax ID already exists for tenant.');
        }

        $supplierId = DB::table('suppliers')->insertGetId([
            'tenant_id' => $tenantId,
            'tax_id' => $taxId,
            'name' => $name,
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return [
            'id' => $supplierId,
            'tenant_id' => $tenantId,
            'tax_id' => $taxId,
            'name' => $name,
            'status' => 'active',
        ];
    }
}
