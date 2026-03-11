<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class InventoryCatalogSeeder extends Seeder
{
    public function run(): void
    {
        $products = [
            [
                'tenant_id' => 10,
                'sku' => 'PARA-500',
                'name' => 'Paracetamol 500mg',
                'status' => 'active',
                'is_controlled' => false,
            ],
            [
                'tenant_id' => 20,
                'sku' => 'AMOX-500',
                'name' => 'Amoxicilina 500mg',
                'status' => 'active',
                'is_controlled' => false,
            ],
        ];

        foreach ($products as $product) {
            DB::table('products')->updateOrInsert(
                [
                    'tenant_id' => $product['tenant_id'],
                    'sku' => $product['sku'],
                ],
                [
                    'name' => $product['name'],
                    'status' => $product['status'],
                    'is_controlled' => $product['is_controlled'],
                    'last_cost' => 0,
                    'average_cost' => 0,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]
            );
        }

        $lots = [
            [
                'tenant_id' => 10,
                'product_sku' => 'PARA-500',
                'code' => 'L-PARA-001',
                'expires_at' => '2027-06-30',
                'stock_quantity' => 60,
                'status' => 'available',
            ],
            [
                'tenant_id' => 10,
                'product_sku' => 'PARA-500',
                'code' => 'L-PARA-002',
                'expires_at' => '2027-12-31',
                'stock_quantity' => 60,
                'status' => 'available',
            ],
            [
                'tenant_id' => 20,
                'product_sku' => 'AMOX-500',
                'code' => 'L-AMOX-001',
                'expires_at' => '2027-10-31',
                'stock_quantity' => 75,
                'status' => 'available',
            ],
        ];

        foreach ($lots as $lot) {
            $productId = DB::table('products')
                ->where('tenant_id', $lot['tenant_id'])
                ->where('sku', $lot['product_sku'])
                ->value('id');

            DB::table('lots')->updateOrInsert(
                [
                    'tenant_id' => $lot['tenant_id'],
                    'code' => $lot['code'],
                ],
                [
                    'product_id' => $productId,
                    'expires_at' => $lot['expires_at'],
                    'stock_quantity' => $lot['stock_quantity'],
                    'status' => $lot['status'],
                    'created_at' => now(),
                    'updated_at' => now(),
                ]
            );
        }
    }
}
