<?php

namespace Tests\Feature\Billing;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class BillingDispatchFlowTest extends TestCase
{
    use RefreshDatabase;

    public function test_dispatches_pending_voucher_and_marks_it_accepted(): void
    {
        $this->seed([
            \Database\Seeders\TenantSeeder::class,
            \Database\Seeders\RbacCatalogSeeder::class,
        ]);

        $user = User::factory()->create();
        $adminRoleId = DB::table('roles')->where('code', 'ADMIN')->value('id');
        $saleUserId = User::factory()->create()->id;

        DB::table('tenant_user')->insert([
            'tenant_id' => 10,
            'user_id' => $user->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('tenant_user_role')->insert([
            'tenant_id' => 10,
            'user_id' => $user->id,
            'role_id' => $adminRoleId,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $saleId = DB::table('sales')->insertGetId([
            'tenant_id' => 10,
            'user_id' => $saleUserId,
            'reference' => 'SALE-DISPATCH-10',
            'status' => 'completed',
            'total_amount' => 88.00,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $voucherId = DB::table('electronic_vouchers')->insertGetId([
            'tenant_id' => 10,
            'sale_id' => $saleId,
            'type' => 'boleta',
            'series' => 'B001',
            'number' => 1,
            'status' => 'pending',
            'sunat_ticket' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('outbox_events')->insert([
            'tenant_id' => 10,
            'aggregate_type' => 'electronic_voucher',
            'aggregate_id' => $voucherId,
            'event_type' => 'voucher.created',
            'payload' => json_encode(['voucher_id' => $voucherId], JSON_THROW_ON_ERROR),
            'status' => 'pending',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->actingAs($user)
            ->withHeader('X-Tenant-Id', '10')
            ->postJson('/billing/outbox/dispatch')
            ->assertOk()
            ->assertJsonPath('data.voucher_id', $voucherId)
            ->assertJsonPath('data.status', 'processed');

        $this->assertDatabaseHas('electronic_vouchers', [
            'id' => $voucherId,
            'status' => 'accepted',
        ]);

        $this->assertDatabaseHas('outbox_events', [
            'tenant_id' => 10,
            'aggregate_id' => $voucherId,
            'status' => 'processed',
        ]);
    }
}
