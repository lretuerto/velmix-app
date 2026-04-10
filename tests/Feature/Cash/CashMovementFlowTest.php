<?php

namespace Tests\Feature\Cash;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class CashMovementFlowTest extends TestCase
{
    use RefreshDatabase;

    public function test_cashier_can_register_manual_cash_movements_and_read_them(): void
    {
        $cashier = $this->seedCashierUser(10);

        $this->actingAs($cashier)
            ->withHeader('X-Tenant-Id', '10')
            ->postJson('/cash/sessions/open', [
                'opening_amount' => 100,
            ])
            ->assertOk();

        $this->actingAs($cashier)
            ->withHeader('X-Tenant-Id', '10')
            ->postJson('/cash/movements', [
                'type' => 'manual_in',
                'amount' => 15,
                'reference' => 'ING-001',
                'notes' => 'Fondo adicional',
            ])
            ->assertOk()
            ->assertJsonPath('data.type', 'manual_in')
            ->assertJsonPath('data.amount', 15);

        $this->actingAs($cashier)
            ->withHeader('X-Tenant-Id', '10')
            ->postJson('/cash/movements', [
                'type' => 'manual_out',
                'amount' => 5,
                'reference' => 'EGR-001',
                'notes' => 'Gasto operativo',
            ])
            ->assertOk()
            ->assertJsonPath('data.type', 'manual_out')
            ->assertJsonPath('data.amount', 5);

        $sessionId = DB::table('cash_sessions')->where('tenant_id', 10)->value('id');

        $this->actingAs($cashier)
            ->withHeader('X-Tenant-Id', '10')
            ->getJson('/cash/sessions/current')
            ->assertOk()
            ->assertJsonPath('data.manual_in_total', 15)
            ->assertJsonPath('data.manual_out_total', 5)
            ->assertJsonPath('data.net_movement_total', 10)
            ->assertJsonPath('data.movement_count', 2)
            ->assertJsonPath('data.expected_amount', 110);

        $this->actingAs($cashier)
            ->withHeader('X-Tenant-Id', '10')
            ->getJson("/cash/sessions/{$sessionId}/movements")
            ->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('data.0.reference', 'ING-001')
            ->assertJsonPath('data.1.reference', 'EGR-001');
    }

    public function test_rejects_manual_out_exceeding_available_cash(): void
    {
        $cashier = $this->seedCashierUser(10);

        $this->actingAs($cashier)
            ->withHeader('X-Tenant-Id', '10')
            ->postJson('/cash/sessions/open', [
                'opening_amount' => 20,
            ])
            ->assertOk();

        $this->actingAs($cashier)
            ->withHeader('X-Tenant-Id', '10')
            ->postJson('/cash/movements', [
                'type' => 'manual_out',
                'amount' => 25,
                'reference' => 'EGR-OVER',
            ])
            ->assertStatus(422);
    }

    public function test_rejects_cash_movement_list_from_other_tenant(): void
    {
        $cashierTenant10 = $this->seedCashierUser(10);
        $cashierTenant20 = $this->seedCashierUser(20);

        $this->actingAs($cashierTenant10)
            ->withHeader('X-Tenant-Id', '10')
            ->postJson('/cash/sessions/open', [
                'opening_amount' => 50,
            ])
            ->assertOk();

        $sessionId = DB::table('cash_sessions')->where('tenant_id', 10)->value('id');

        $this->actingAs($cashierTenant20)
            ->withHeader('X-Tenant-Id', '20')
            ->getJson("/cash/sessions/{$sessionId}/movements")
            ->assertStatus(404);
    }

    private function seedCashierUser(int $tenantId): User
    {
        $this->seed([
            \Database\Seeders\TenantSeeder::class,
            \Database\Seeders\RbacCatalogSeeder::class,
        ]);

        $user = User::factory()->create();
        $roleId = DB::table('roles')->where('code', 'CAJERO')->value('id');

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
