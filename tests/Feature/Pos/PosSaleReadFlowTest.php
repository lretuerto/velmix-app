<?php

namespace Tests\Feature\Pos;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class PosSaleReadFlowTest extends TestCase
{
    use RefreshDatabase;

    public function test_lists_sales_for_current_tenant_only(): void
    {
        [$saleId] = $this->createCompletedSaleForTenant(10, 'SALE-LIST-10');
        $this->createCompletedSaleForTenant(20, 'SALE-LIST-20');
        $user = $this->seedUserWithRole(10, 'CAJERO');

        $this->actingAs($user)
            ->withHeader('X-Tenant-Id', '10')
            ->getJson('/pos/sales')
            ->assertOk()
            ->assertJsonFragment([
                'id' => $saleId,
                'reference' => 'SALE-LIST-10',
                'payment_method' => 'cash',
            ])
            ->assertJsonMissing([
                'reference' => 'SALE-LIST-20',
            ]);
    }

    public function test_reads_sale_detail_with_items_and_voucher_summary(): void
    {
        [$saleId, $lotId] = $this->createCompletedSaleForTenant(10, 'SALE-DETAIL-10');
        $user = $this->seedUserWithRole(10, 'CAJERO');

        DB::table('electronic_vouchers')->insert([
            'tenant_id' => 10,
            'sale_id' => $saleId,
            'type' => 'boleta',
            'series' => 'B001',
            'number' => 1,
            'status' => 'accepted',
            'sunat_ticket' => 'SUNAT-123456',
            'rejection_reason' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->actingAs($user)
            ->withHeader('X-Tenant-Id', '10')
            ->getJson("/pos/sales/{$saleId}")
            ->assertOk()
            ->assertJsonPath('data.id', $saleId)
            ->assertJsonPath('data.payment_method', 'cash')
            ->assertJsonPath('data.voucher.status', 'accepted')
            ->assertJsonPath('data.movement_count', 1)
            ->assertJsonPath('data.gross_cost', 6.5)
            ->assertJsonPath('data.gross_margin', 11)
            ->assertJsonPath('data.items.0.unit_cost_snapshot', 1.3)
            ->assertJsonPath('data.items.0.lot_code', DB::table('lots')->where('id', $lotId)->value('code'));
    }

    public function test_reads_cancelled_sale_detail(): void
    {
        [$saleId] = $this->createCompletedSaleForTenant(10, 'SALE-CANCELLED-10');
        $admin = $this->seedUserWithRole(10, 'ADMIN');

        $this->actingAs($admin)
            ->withHeader('X-Tenant-Id', '10')
            ->postJson("/pos/sales/{$saleId}/cancel", [
                'reason' => 'Anulacion operativa',
            ])
            ->assertOk();

        $this->actingAs($admin)
            ->withHeader('X-Tenant-Id', '10')
            ->getJson("/pos/sales/{$saleId}")
            ->assertOk()
            ->assertJsonPath('data.status', 'cancelled')
            ->assertJsonPath('data.cancel_reason', 'Anulacion operativa');
    }

    private function createCompletedSaleForTenant(int $tenantId, string $reference): array
    {
        $this->seed([
            \Database\Seeders\TenantSeeder::class,
            \Database\Seeders\RbacCatalogSeeder::class,
            \Database\Seeders\InventoryCatalogSeeder::class,
        ]);

        $user = $this->seedUserWithRole($tenantId, 'CAJERO');
        $lotId = DB::table('lots')->where('tenant_id', $tenantId)->value('id');
        $lotCode = DB::table('lots')->where('id', $lotId)->value('code');
        $productId = DB::table('lots')->where('id', $lotId)->value('product_id');

        DB::table('sales')->insert([
            'id' => DB::table('sales')->max('id') + 1,
            'tenant_id' => $tenantId,
            'user_id' => $user->id,
            'cancelled_by_user_id' => null,
            'reference' => $reference,
            'status' => 'completed',
            'cancel_reason' => null,
            'cancelled_at' => null,
            'total_amount' => 17.50,
            'gross_cost' => 6.50,
            'gross_margin' => 11.00,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $saleId = DB::table('sales')->where('reference', $reference)->value('id');

        DB::table('sale_items')->insert([
            'sale_id' => $saleId,
            'lot_id' => $lotId,
            'product_id' => $productId,
            'quantity' => 5,
            'unit_price' => 3.50,
            'unit_cost_snapshot' => 1.30,
            'line_total' => 17.50,
            'cost_amount' => 6.50,
            'gross_margin' => 11.00,
            'prescription_code' => null,
            'approval_code' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('stock_movements')->insert([
            'tenant_id' => $tenantId,
            'lot_id' => $lotId,
            'product_id' => $productId,
            'sale_id' => $saleId,
            'type' => 'sale',
            'quantity' => -5,
            'reference' => $reference,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return [$saleId, $lotId, $lotCode];
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
