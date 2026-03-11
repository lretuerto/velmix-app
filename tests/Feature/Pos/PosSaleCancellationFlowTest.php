<?php

namespace Tests\Feature\Pos;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class PosSaleCancellationFlowTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_can_cancel_sale_and_restore_stock(): void
    {
        [$saleId, $lotId] = $this->createCompletedSaleForTenant10();
        $admin = $this->seedUserWithRole(10, 'ADMIN');

        $this->actingAs($admin)
            ->withHeader('X-Tenant-Id', '10')
            ->postJson("/pos/sales/{$saleId}/cancel", [
                'reason' => 'Cliente desiste antes de emitir comprobante',
            ])
            ->assertOk()
            ->assertJsonPath('data.sale_id', $saleId)
            ->assertJsonPath('data.status', 'cancelled');

        $this->assertDatabaseHas('sales', [
            'id' => $saleId,
            'status' => 'cancelled',
            'cancelled_by_user_id' => $admin->id,
        ]);

        $this->assertDatabaseHas('lots', [
            'id' => $lotId,
            'stock_quantity' => 60,
        ]);

        $this->assertDatabaseHas('stock_movements', [
            'sale_id' => $saleId,
            'lot_id' => $lotId,
            'type' => 'sale_reversal',
            'quantity' => 5,
        ]);
    }

    public function test_rejects_cancellation_when_sale_has_voucher(): void
    {
        [$saleId] = $this->createCompletedSaleForTenant10();
        $admin = $this->seedUserWithRole(10, 'ADMIN');

        DB::table('electronic_vouchers')->insert([
            'tenant_id' => 10,
            'sale_id' => $saleId,
            'type' => 'boleta',
            'series' => 'B001',
            'number' => 1,
            'status' => 'accepted',
            'sunat_ticket' => 'SUNAT-000111',
            'rejection_reason' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->actingAs($admin)
            ->withHeader('X-Tenant-Id', '10')
            ->postJson("/pos/sales/{$saleId}/cancel", [
                'reason' => 'Intento invalido',
            ])
            ->assertStatus(422);
    }

    public function test_rejects_cancellation_for_sale_from_other_tenant(): void
    {
        [$saleId] = $this->createCompletedSaleForTenant10();
        $admin = $this->seedUserWithRole(20, 'ADMIN');

        $this->actingAs($admin)
            ->withHeader('X-Tenant-Id', '20')
            ->postJson("/pos/sales/{$saleId}/cancel", [
                'reason' => 'Intento cruzado',
            ])
            ->assertStatus(404);
    }

    private function createCompletedSaleForTenant10(): array
    {
        $this->seed([
            \Database\Seeders\TenantSeeder::class,
            \Database\Seeders\RbacCatalogSeeder::class,
            \Database\Seeders\InventoryCatalogSeeder::class,
        ]);

        $cashier = $this->seedUserWithRole(10, 'CAJERO');
        $lotId = DB::table('lots')->where('tenant_id', 10)->where('code', 'L-PARA-001')->value('id');

        $response = $this->actingAs($cashier)
            ->withHeader('X-Tenant-Id', '10')
            ->postJson('/pos/sales', [
                'lot_id' => $lotId,
                'quantity' => 5,
                'unit_price' => 3.50,
            ]);

        $response->assertOk();
        $saleId = $response->json('data.sale_id');

        return [$saleId, $lotId];
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
