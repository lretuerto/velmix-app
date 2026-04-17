<?php

namespace Tests\Feature\Reports;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class SalesProfitabilityReportFlowTest extends TestCase
{
    use RefreshDatabase;

    public function test_reads_sales_profitability_summary_for_current_tenant(): void
    {
        $this->seed([
            \Database\Seeders\TenantSeeder::class,
            \Database\Seeders\RbacCatalogSeeder::class,
            \Database\Seeders\InventoryCatalogSeeder::class,
        ]);

        $admin = $this->seedUserWithRole(10, 'ADMIN');
        $user10 = User::factory()->create();
        $user20 = User::factory()->create();
        $product10 = DB::table('products')->where('tenant_id', 10)->value('id');
        $product20 = DB::table('products')->where('tenant_id', 20)->value('id');
        $lot10 = DB::table('lots')->where('tenant_id', 10)->value('id');
        $lot20 = DB::table('lots')->where('tenant_id', 20)->value('id');

        $saleId1 = DB::table('sales')->insertGetId([
            'tenant_id' => 10,
            'user_id' => $user10->id,
            'reference' => 'SALE-PROFIT-001',
            'status' => 'completed',
            'total_amount' => 20.00,
            'gross_cost' => 8.00,
            'gross_margin' => 12.00,
            'created_at' => '2026-03-11 09:00:00',
            'updated_at' => '2026-03-11 09:00:00',
        ]);

        $saleId2 = DB::table('sales')->insertGetId([
            'tenant_id' => 10,
            'user_id' => $user10->id,
            'reference' => 'SALE-PROFIT-002',
            'status' => 'completed',
            'total_amount' => 15.00,
            'gross_cost' => 6.00,
            'gross_margin' => 9.00,
            'created_at' => '2026-03-11 12:00:00',
            'updated_at' => '2026-03-11 12:00:00',
        ]);

        DB::table('sales')->insert([
            'tenant_id' => 10,
            'user_id' => $user10->id,
            'reference' => 'SALE-CANCELLED-IGNORE',
            'status' => 'cancelled',
            'total_amount' => 99.00,
            'gross_cost' => 50.00,
            'gross_margin' => 49.00,
            'created_at' => '2026-03-11 13:00:00',
            'updated_at' => '2026-03-11 13:00:00',
        ]);

        DB::table('sales')->insert([
            'tenant_id' => 20,
            'user_id' => $user20->id,
            'reference' => 'SALE-FOREIGN',
            'status' => 'completed',
            'total_amount' => 200.00,
            'gross_cost' => 150.00,
            'gross_margin' => 50.00,
            'created_at' => '2026-03-11 10:00:00',
            'updated_at' => '2026-03-11 10:00:00',
        ]);

        DB::table('sale_items')->insert([
            [
                'sale_id' => $saleId1,
                'lot_id' => $lot10,
                'product_id' => $product10,
                'quantity' => 4,
                'unit_price' => 5.00,
                'unit_cost_snapshot' => 2.00,
                'line_total' => 20.00,
                'cost_amount' => 8.00,
                'gross_margin' => 12.00,
                'prescription_code' => null,
                'approval_code' => null,
                'created_at' => '2026-03-11 09:00:00',
                'updated_at' => '2026-03-11 09:00:00',
            ],
            [
                'sale_id' => $saleId2,
                'lot_id' => $lot10,
                'product_id' => $product10,
                'quantity' => 3,
                'unit_price' => 5.00,
                'unit_cost_snapshot' => 2.00,
                'line_total' => 15.00,
                'cost_amount' => 6.00,
                'gross_margin' => 9.00,
                'prescription_code' => null,
                'approval_code' => null,
                'created_at' => '2026-03-11 12:00:00',
                'updated_at' => '2026-03-11 12:00:00',
            ],
            [
                'sale_id' => DB::table('sales')->where('reference', 'SALE-FOREIGN')->value('id'),
                'lot_id' => $lot20,
                'product_id' => $product20,
                'quantity' => 10,
                'unit_price' => 20.00,
                'unit_cost_snapshot' => 15.00,
                'line_total' => 200.00,
                'cost_amount' => 150.00,
                'gross_margin' => 50.00,
                'prescription_code' => null,
                'approval_code' => null,
                'created_at' => '2026-03-11 10:00:00',
                'updated_at' => '2026-03-11 10:00:00',
            ],
        ]);

        $this->actingAs($admin)
            ->withHeader('X-Tenant-Id', '10')
            ->getJson('/reports/sales-profitability?date=2026-03-11')
            ->assertOk()
            ->assertJsonPath('data.tenant_id', 10)
            ->assertJsonPath('data.summary.sales_count', 2)
            ->assertJsonPath('data.summary.revenue_total', 35)
            ->assertJsonPath('data.summary.gross_cost_total', 14)
            ->assertJsonPath('data.summary.gross_margin_total', 21)
            ->assertJsonPath('data.summary.margin_pct', 60)
            ->assertJsonPath('data.products.0.sku', 'PARA-500')
            ->assertJsonPath('data.products.0.quantity_sold', 7)
            ->assertJsonPath('data.products.0.gross_margin_total', 21)
            ->assertJsonMissing([
                'sku' => 'AMOX-500',
            ]);
    }

    public function test_cashier_cannot_read_sales_profitability_summary(): void
    {
        $this->seed([
            \Database\Seeders\TenantSeeder::class,
            \Database\Seeders\RbacCatalogSeeder::class,
        ]);

        $cashier = $this->seedUserWithRole(10, 'CAJERO');

        $this->actingAs($cashier)
            ->withHeader('X-Tenant-Id', '10')
            ->getJson('/reports/sales-profitability?date=2026-03-11')
            ->assertStatus(403);
    }

    private function seedUserWithRole(int $tenantId, string $roleCode): User
    {
        $user = User::factory()->create();
        $roleId = DB::table('roles')->where('code', $roleCode)->value('id');

        DB::table('tenant_user')->insert([
            'tenant_id' => $tenantId,
            'user_id' => $user->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('tenant_user_role')->insert([
            'tenant_id' => $tenantId,
            'user_id' => $user->id,
            'role_id' => $roleId,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $user;
    }
}
