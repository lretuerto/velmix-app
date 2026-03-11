<?php

namespace Tests\Feature\Cash;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class CashSessionFlowTest extends TestCase
{
    use RefreshDatabase;

    public function test_cashier_can_open_cash_session(): void
    {
        $user = $this->seedCashierUser();

        $this->actingAs($user)
            ->withHeader('X-Tenant-Id', '10')
            ->postJson('/cash/sessions/open', [
                'opening_amount' => 100,
            ])
            ->assertOk()
            ->assertJsonPath('data.status', 'open')
            ->assertJsonPath('data.opening_amount', 100);

        $this->assertDatabaseHas('cash_sessions', [
            'tenant_id' => 10,
            'opened_by_user_id' => $user->id,
            'status' => 'open',
        ]);
    }

    public function test_cannot_open_second_cash_session_while_one_is_active(): void
    {
        $user = $this->seedCashierUser();

        $this->actingAs($user)
            ->withHeader('X-Tenant-Id', '10')
            ->postJson('/cash/sessions/open', [
                'opening_amount' => 100,
            ])
            ->assertOk();

        $this->actingAs($user)
            ->withHeader('X-Tenant-Id', '10')
            ->postJson('/cash/sessions/open', [
                'opening_amount' => 50,
            ])
            ->assertStatus(422);
    }

    public function test_can_read_current_cash_session_summary(): void
    {
        $user = $this->seedCashierUser();

        $this->actingAs($user)
            ->withHeader('X-Tenant-Id', '10')
            ->postJson('/cash/sessions/open', [
                'opening_amount' => 100,
            ])
            ->assertOk();

        DB::table('sales')->insert([
            'tenant_id' => 10,
            'user_id' => $user->id,
            'reference' => 'SALE-CASH-001',
            'status' => 'completed',
            'total_amount' => 25.50,
            'created_at' => now()->addMinute(),
            'updated_at' => now()->addMinute(),
        ]);

        $this->actingAs($user)
            ->withHeader('X-Tenant-Id', '10')
            ->getJson('/cash/sessions/current')
            ->assertOk()
            ->assertJsonPath('data.sales_count', 1)
            ->assertJsonPath('data.sales_total', 25.5)
            ->assertJsonPath('data.expected_amount', 125.5);
    }

    public function test_can_close_cash_session_and_compute_discrepancy(): void
    {
        $user = $this->seedCashierUser();

        $this->actingAs($user)
            ->withHeader('X-Tenant-Id', '10')
            ->postJson('/cash/sessions/open', [
                'opening_amount' => 100,
            ])
            ->assertOk();

        DB::table('sales')->insert([
            [
                'tenant_id' => 10,
                'user_id' => $user->id,
                'reference' => 'SALE-CASH-001',
                'status' => 'completed',
                'total_amount' => 25.50,
                'created_at' => now()->addMinute(),
                'updated_at' => now()->addMinute(),
            ],
            [
                'tenant_id' => 10,
                'user_id' => $user->id,
                'reference' => 'SALE-CASH-002',
                'status' => 'completed',
                'total_amount' => 10.00,
                'created_at' => now()->addMinutes(2),
                'updated_at' => now()->addMinutes(2),
            ],
        ]);

        $this->actingAs($user)
            ->withHeader('X-Tenant-Id', '10')
            ->postJson('/cash/sessions/current/close', [
                'counted_amount' => 140,
            ])
            ->assertOk()
            ->assertJsonPath('data.status', 'closed')
            ->assertJsonPath('data.sales_count', 2)
            ->assertJsonPath('data.expected_amount', 135.5)
            ->assertJsonPath('data.counted_amount', 140)
            ->assertJsonPath('data.discrepancy_amount', 4.5);

        $this->assertDatabaseHas('cash_sessions', [
            'tenant_id' => 10,
            'status' => 'closed',
            'expected_amount' => 135.50,
            'counted_amount' => 140.00,
            'discrepancy_amount' => 4.50,
        ]);
    }

    public function test_can_list_cash_session_history_for_current_tenant(): void
    {
        $user = $this->seedCashierUser();

        $this->actingAs($user)
            ->withHeader('X-Tenant-Id', '10')
            ->postJson('/cash/sessions/open', [
                'opening_amount' => 80,
            ])
            ->assertOk();

        $this->actingAs($user)
            ->withHeader('X-Tenant-Id', '10')
            ->postJson('/cash/sessions/current/close', [
                'counted_amount' => 80,
            ])
            ->assertOk();

        $this->actingAs($user)
            ->withHeader('X-Tenant-Id', '10')
            ->getJson('/cash/sessions')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.status', 'closed');
    }

    public function test_rejects_cash_session_detail_from_other_tenant(): void
    {
        $cashierTenant10 = $this->seedCashierUser();

        $this->actingAs($cashierTenant10)
            ->withHeader('X-Tenant-Id', '10')
            ->postJson('/cash/sessions/open', [
                'opening_amount' => 60,
            ])
            ->assertOk();

        $sessionId = DB::table('cash_sessions')->where('tenant_id', 10)->value('id');

        $foreignUser = User::factory()->create();
        $roleId = DB::table('roles')->where('code', 'CAJERO')->value('id');

        DB::table('tenant_user')->insert([
            'tenant_id' => 20,
            'user_id' => $foreignUser->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('tenant_user_role')->insert([
            'tenant_id' => 20,
            'user_id' => $foreignUser->id,
            'role_id' => $roleId,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->actingAs($foreignUser)
            ->withHeader('X-Tenant-Id', '20')
            ->getJson("/cash/sessions/{$sessionId}")
            ->assertStatus(404);
    }

    private function seedCashierUser(): User
    {
        $this->seed([
            \Database\Seeders\TenantSeeder::class,
            \Database\Seeders\RbacCatalogSeeder::class,
        ]);

        $user = User::factory()->create();
        $roleId = DB::table('roles')->where('code', 'CAJERO')->value('id');

        DB::table('tenant_user')->insert([
            'tenant_id' => 10,
            'user_id' => $user->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('tenant_user_role')->insert([
            'tenant_id' => 10,
            'user_id' => $user->id,
            'role_id' => $roleId,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $user;
    }
}
