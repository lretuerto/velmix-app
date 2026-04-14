<?php

namespace Tests\Feature\Platform;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class IdempotencyFlowTest extends TestCase
{
    use RefreshDatabase;

    public function test_replays_same_voucher_response_for_same_idempotency_key_and_blocks_payload_drift(): void
    {
        $this->seed([
            \Database\Seeders\TenantSeeder::class,
            \Database\Seeders\RbacCatalogSeeder::class,
        ]);

        $admin = $this->seedTenantAdminUser(10);
        $saleA = $this->seedCompletedSale(10, 'SALE-IDEMP-A');
        $saleB = $this->seedCompletedSale(10, 'SALE-IDEMP-B');

        $first = $this->actingAs($admin)
            ->withHeader('X-Tenant-Id', '10')
            ->withHeader('Idempotency-Key', 'voucher-issue-001')
            ->postJson('/billing/vouchers', [
                'sale_id' => $saleA,
                'type' => 'boleta',
            ]);

        $first->assertOk()
            ->assertHeader('X-Idempotency-Status', 'stored');

        $voucherId = (int) $first->json('data.id');

        $this->actingAs($admin)
            ->withHeader('X-Tenant-Id', '10')
            ->withHeader('Idempotency-Key', 'voucher-issue-001')
            ->postJson('/billing/vouchers', [
                'sale_id' => $saleA,
                'type' => 'boleta',
            ])
            ->assertOk()
            ->assertHeader('X-Idempotency-Status', 'replayed')
            ->assertJsonPath('data.id', $voucherId);

        $this->assertSame(1, DB::table('electronic_vouchers')->where('sale_id', $saleA)->count());
        $this->assertSame(1, DB::table('outbox_events')->where('aggregate_type', 'electronic_voucher')->where('aggregate_id', $voucherId)->count());

        $this->actingAs($admin)
            ->withHeader('X-Tenant-Id', '10')
            ->withHeader('Idempotency-Key', 'voucher-issue-001')
            ->postJson('/billing/vouchers', [
                'sale_id' => $saleB,
                'type' => 'boleta',
            ])
            ->assertStatus(409)
            ->assertJsonPath('message', 'Idempotency key already exists for a different request payload.');
    }

    private function seedCompletedSale(int $tenantId, string $reference): int
    {
        return (int) DB::table('sales')->insertGetId([
            'tenant_id' => $tenantId,
            'user_id' => User::factory()->create()->id,
            'reference' => $reference,
            'status' => 'completed',
            'payment_method' => 'cash',
            'total_amount' => 25,
            'gross_cost' => 10,
            'gross_margin' => 15,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function seedTenantAdminUser(int $tenantId): User
    {
        $user = User::factory()->create();
        $roleId = DB::table('roles')->where('code', 'ADMIN')->value('id');

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
