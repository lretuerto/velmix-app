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
            'gross_cost' => 10.00,
            'gross_margin' => 15.50,
            'created_at' => now()->addMinute(),
            'updated_at' => now()->addMinute(),
        ]);

        $this->actingAs($user)
            ->withHeader('X-Tenant-Id', '10')
            ->getJson('/cash/sessions/current')
            ->assertOk()
            ->assertJsonPath('data.sales_count', 1)
            ->assertJsonPath('data.sales_total', 25.5)
            ->assertJsonPath('data.cash_sales_total', 25.5)
            ->assertJsonPath('data.card_sales_total', 0)
            ->assertJsonPath('data.transfer_sales_total', 0)
            ->assertJsonPath('data.gross_cost_total', 10)
            ->assertJsonPath('data.gross_margin_total', 15.5)
            ->assertJsonPath('data.margin_pct', 60.78)
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
                'gross_cost' => 10.00,
                'gross_margin' => 15.50,
                'created_at' => now()->addMinute(),
                'updated_at' => now()->addMinute(),
            ],
            [
                'tenant_id' => 10,
                'user_id' => $user->id,
                'reference' => 'SALE-CASH-002',
                'status' => 'completed',
                'total_amount' => 10.00,
                'gross_cost' => 4.00,
                'gross_margin' => 6.00,
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
            ->assertJsonPath('data.sales_total', 35.5)
            ->assertJsonPath('data.cash_sales_total', 35.5)
            ->assertJsonPath('data.card_sales_total', 0)
            ->assertJsonPath('data.transfer_sales_total', 0)
            ->assertJsonPath('data.gross_cost_total', 14)
            ->assertJsonPath('data.gross_margin_total', 21.5)
            ->assertJsonPath('data.margin_pct', 60.56)
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

    public function test_can_close_cash_session_with_denominations_and_read_user_context(): void
    {
        $cashier = $this->seedCashierUser('Caja Apertura');
        $admin = $this->seedAdminUser('Supervisor Cierre');

        $this->actingAs($cashier)
            ->withHeader('X-Tenant-Id', '10')
            ->postJson('/cash/sessions/open', [
                'opening_amount' => 100,
            ])
            ->assertOk();

        DB::table('sales')->insert([
            'tenant_id' => 10,
            'user_id' => $cashier->id,
            'reference' => 'SALE-CASH-DENOM-001',
            'status' => 'completed',
            'payment_method' => 'cash',
            'total_amount' => 30.00,
            'gross_cost' => 12.00,
            'gross_margin' => 18.00,
            'created_at' => now()->addMinute(),
            'updated_at' => now()->addMinute(),
        ]);

        $closeResponse = $this->actingAs($admin)
            ->withHeader('X-Tenant-Id', '10')
            ->postJson('/cash/sessions/current/close', [
                'denominations' => [
                    ['value' => 50, 'quantity' => 2],
                    ['value' => 20, 'quantity' => 1],
                    ['value' => 10, 'quantity' => 1],
                ],
            ]);

        $closeResponse->assertOk()
            ->assertJsonPath('data.counted_amount', 130)
            ->assertJsonPath('data.discrepancy_amount', 0)
            ->assertJsonPath('data.opened_by.name', 'Caja Apertura')
            ->assertJsonPath('data.closed_by.name', 'Supervisor Cierre')
            ->assertJsonPath('data.denominations.0.value', 50)
            ->assertJsonPath('data.denominations.0.quantity', 2);

        $sessionId = DB::table('cash_sessions')->where('tenant_id', 10)->value('id');

        $this->assertDatabaseHas('cash_session_denominations', [
            'tenant_id' => 10,
            'cash_session_id' => $sessionId,
            'value' => 50.00,
            'quantity' => 2,
            'subtotal' => 100.00,
        ]);

        $this->actingAs($admin)
            ->withHeader('X-Tenant-Id', '10')
            ->getJson("/cash/sessions/{$sessionId}")
            ->assertOk()
            ->assertJsonPath('data.opened_by.name', 'Caja Apertura')
            ->assertJsonPath('data.closed_by.name', 'Supervisor Cierre')
            ->assertJsonPath('data.denominations.1.value', 20)
            ->assertJsonPath('data.denominations.2.value', 10);
    }

    public function test_rejects_close_when_denominations_do_not_match_counted_amount(): void
    {
        $cashier = $this->seedCashierUser();

        $this->actingAs($cashier)
            ->withHeader('X-Tenant-Id', '10')
            ->postJson('/cash/sessions/open', [
                'opening_amount' => 100,
            ])
            ->assertOk();

        $this->actingAs($cashier)
            ->withHeader('X-Tenant-Id', '10')
            ->postJson('/cash/sessions/current/close', [
                'counted_amount' => 90,
                'denominations' => [
                    ['value' => 50, 'quantity' => 2],
                ],
            ])
            ->assertStatus(422);
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

        DB::table('cash_sessions')
            ->where('tenant_id', 10)
            ->where('status', 'open')
            ->update([
                'opened_at' => now()->subMinutes(5),
                'updated_at' => now()->subMinutes(5),
            ]);

        DB::table('sales')->insert([
            'tenant_id' => 10,
            'user_id' => $user->id,
            'reference' => 'SALE-CASH-HISTORY-001',
            'status' => 'completed',
            'total_amount' => 20.00,
            'gross_cost' => 8.00,
            'gross_margin' => 12.00,
            'created_at' => now()->subMinute(),
            'updated_at' => now()->subMinute(),
        ]);

        $this->actingAs($user)
            ->withHeader('X-Tenant-Id', '10')
            ->postJson('/cash/sessions/current/close', [
                'counted_amount' => 100,
            ])
            ->assertOk();

        $this->actingAs($user)
            ->withHeader('X-Tenant-Id', '10')
            ->getJson('/cash/sessions')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.status', 'closed')
            ->assertJsonPath('data.0.sales_count', 1)
            ->assertJsonPath('data.0.sales_total', 20)
            ->assertJsonPath('data.0.cash_sales_total', 20)
            ->assertJsonPath('data.0.card_sales_total', 0)
            ->assertJsonPath('data.0.transfer_sales_total', 0)
            ->assertJsonPath('data.0.gross_cost_total', 8)
            ->assertJsonPath('data.0.gross_margin_total', 12)
            ->assertJsonPath('data.0.margin_pct', 60)
            ->assertJsonPath('data.0.expected_amount', 100);
    }

    public function test_can_read_cash_session_detail_with_profitability_summary(): void
    {
        $user = $this->seedCashierUser();

        $this->actingAs($user)
            ->withHeader('X-Tenant-Id', '10')
            ->postJson('/cash/sessions/open', [
                'opening_amount' => 50,
            ])
            ->assertOk();

        DB::table('sales')->insert([
            [
                'tenant_id' => 10,
                'user_id' => $user->id,
                'reference' => 'SALE-CASH-DETAIL-001',
                'status' => 'completed',
                'total_amount' => 30.00,
                'gross_cost' => 12.00,
                'gross_margin' => 18.00,
                'created_at' => now()->addMinute(),
                'updated_at' => now()->addMinute(),
            ],
            [
                'tenant_id' => 10,
                'user_id' => $user->id,
                'reference' => 'SALE-CASH-DETAIL-CANCELLED',
                'status' => 'cancelled',
                'total_amount' => 90.00,
                'gross_cost' => 45.00,
                'gross_margin' => 45.00,
                'created_at' => now()->addMinutes(2),
                'updated_at' => now()->addMinutes(2),
            ],
        ]);

        $sessionId = DB::table('cash_sessions')->where('tenant_id', 10)->value('id');

        $this->actingAs($user)
            ->withHeader('X-Tenant-Id', '10')
            ->getJson("/cash/sessions/{$sessionId}")
            ->assertOk()
            ->assertJsonPath('data.sales_count', 1)
            ->assertJsonPath('data.sales_total', 30)
            ->assertJsonPath('data.cash_sales_total', 30)
            ->assertJsonPath('data.card_sales_total', 0)
            ->assertJsonPath('data.transfer_sales_total', 0)
            ->assertJsonPath('data.gross_cost_total', 12)
            ->assertJsonPath('data.gross_margin_total', 18)
            ->assertJsonPath('data.margin_pct', 60)
            ->assertJsonPath('data.expected_amount', 80);
    }

    public function test_non_cash_sales_do_not_increase_expected_amount(): void
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
                'reference' => 'SALE-CASH-METHOD-001',
                'status' => 'completed',
                'payment_method' => 'cash',
                'total_amount' => 20.00,
                'gross_cost' => 8.00,
                'gross_margin' => 12.00,
                'created_at' => now()->addMinute(),
                'updated_at' => now()->addMinute(),
            ],
            [
                'tenant_id' => 10,
                'user_id' => $user->id,
                'reference' => 'SALE-CASH-METHOD-002',
                'status' => 'completed',
                'payment_method' => 'card',
                'total_amount' => 30.00,
                'gross_cost' => 10.00,
                'gross_margin' => 20.00,
                'created_at' => now()->addMinutes(2),
                'updated_at' => now()->addMinutes(2),
            ],
        ]);

        $this->actingAs($user)
            ->withHeader('X-Tenant-Id', '10')
            ->getJson('/cash/sessions/current')
            ->assertOk()
            ->assertJsonPath('data.sales_count', 2)
            ->assertJsonPath('data.sales_total', 50)
            ->assertJsonPath('data.cash_sales_total', 20)
            ->assertJsonPath('data.card_sales_total', 30)
            ->assertJsonPath('data.transfer_sales_total', 0)
            ->assertJsonPath('data.expected_amount', 120);
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

    private function seedCashierUser(string $name = 'Cajero Prueba'): User
    {
        $this->seed([
            \Database\Seeders\TenantSeeder::class,
            \Database\Seeders\RbacCatalogSeeder::class,
        ]);

        $user = User::factory()->create(['name' => $name]);
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

    private function seedAdminUser(string $name = 'Admin Prueba'): User
    {
        $user = User::factory()->create(['name' => $name]);
        $roleId = DB::table('roles')->where('code', 'ADMIN')->value('id');

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
