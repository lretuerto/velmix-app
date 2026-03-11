<?php

namespace Tests\Feature\Billing;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class BillingVoucherFlowTest extends TestCase
{
    use RefreshDatabase;

    public function test_creates_pending_voucher_and_outbox_event_from_sale(): void
    {
        $saleId = $this->createSaleForTenant(10);
        $user = $this->createBillingUserForTenant(10, 'CAJERO');

        $this->actingAs($user)
            ->withHeader('X-Tenant-Id', '10')
            ->postJson('/billing/vouchers', [
                'sale_id' => $saleId,
                'type' => 'boleta',
            ])
            ->assertOk()
            ->assertJsonPath('data.series', 'B001')
            ->assertJsonPath('data.number', 1)
            ->assertJsonPath('data.status', 'pending');

        $voucherId = DB::table('electronic_vouchers')->where('sale_id', $saleId)->value('id');

        $this->assertDatabaseHas('outbox_events', [
            'tenant_id' => 10,
            'aggregate_type' => 'electronic_voucher',
            'aggregate_id' => $voucherId,
            'event_type' => 'voucher.created',
            'status' => 'pending',
        ]);
    }

    public function test_rejects_voucher_creation_for_sale_from_other_tenant(): void
    {
        $saleId = $this->createSaleForTenant(20);
        $user = $this->createBillingUserForTenant(10, 'CAJERO');

        $this->actingAs($user)
            ->withHeader('X-Tenant-Id', '10')
            ->postJson('/billing/vouchers', [
                'sale_id' => $saleId,
                'type' => 'boleta',
            ])
            ->assertStatus(404);
    }

    private function createSaleForTenant(int $tenantId): int
    {
        $this->seed([
            \Database\Seeders\TenantSeeder::class,
            \Database\Seeders\RbacCatalogSeeder::class,
        ]);

        $userId = User::factory()->create()->id;

        return DB::table('sales')->insertGetId([
            'tenant_id' => $tenantId,
            'user_id' => $userId,
            'reference' => 'SALE-BASE-'.$tenantId,
            'status' => 'completed',
            'total_amount' => 50.00,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function createBillingUserForTenant(int $tenantId, string $roleCode): User
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
