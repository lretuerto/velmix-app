<?php

namespace Tests\Feature\Sales;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class CustomerFlowTest extends TestCase
{
    use RefreshDatabase;

    public function test_cashier_can_create_and_list_customers_for_current_tenant(): void
    {
        $cashier = $this->seedUserWithRole(10, 'CAJERO');
        $this->seedUserWithRole(20, 'CAJERO');

        $this->actingAs($cashier)
            ->withHeader('X-Tenant-Id', '10')
            ->postJson('/sales/customers', [
                'document_type' => 'dni',
                'document_number' => '12345678',
                'name' => 'Cliente Mostrador',
                'phone' => '999111222',
                'email' => 'cliente@example.com',
                'credit_limit' => 150,
                'credit_days' => 15,
            ])
            ->assertOk()
            ->assertJsonPath('data.document_number', '12345678')
            ->assertJsonPath('data.credit_limit', 150)
            ->assertJsonPath('data.credit_days', 15);

        DB::table('customers')->insert([
            'tenant_id' => 20,
            'document_type' => 'dni',
            'document_number' => '87654321',
            'name' => 'Cliente Otro Tenant',
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->actingAs($cashier)
            ->withHeader('X-Tenant-Id', '10')
            ->getJson('/sales/customers')
            ->assertOk()
            ->assertJsonFragment([
                'document_number' => '12345678',
                'name' => 'Cliente Mostrador',
                'credit_limit' => 150,
                'outstanding_total' => 0,
            ])
            ->assertJsonMissing([
                'document_number' => '87654321',
            ]);
    }

    public function test_rejects_duplicate_customer_document_in_same_tenant(): void
    {
        $cashier = $this->seedUserWithRole(10, 'CAJERO');

        DB::table('customers')->insert([
            'tenant_id' => 10,
            'document_type' => 'dni',
            'document_number' => '12345678',
            'name' => 'Cliente Inicial',
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->actingAs($cashier)
            ->withHeader('X-Tenant-Id', '10')
            ->postJson('/sales/customers', [
                'document_type' => 'dni',
                'document_number' => '12345678',
                'name' => 'Cliente Duplicado',
            ])
            ->assertStatus(422);
    }

    public function test_cashier_can_update_customer_credit_policy(): void
    {
        $cashier = $this->seedUserWithRole(10, 'CAJERO');
        $customerId = DB::table('customers')->insertGetId([
            'tenant_id' => 10,
            'document_type' => 'dni',
            'document_number' => '23456789',
            'name' => 'Cliente Credito',
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->actingAs($cashier)
            ->withHeader('X-Tenant-Id', '10')
            ->patchJson("/sales/customers/{$customerId}", [
                'credit_limit' => 220,
                'credit_days' => 21,
                'block_on_overdue' => false,
                'status' => 'inactive',
            ])
            ->assertOk()
            ->assertJsonPath('data.credit_limit', 220)
            ->assertJsonPath('data.credit_days', 21)
            ->assertJsonPath('data.block_on_overdue', false)
            ->assertJsonPath('data.status', 'inactive');

        $this->assertDatabaseHas('customers', [
            'id' => $customerId,
            'credit_limit' => 220.00,
            'credit_days' => 21,
            'block_on_overdue' => 0,
            'status' => 'inactive',
        ]);
    }

    private function seedUserWithRole(int $tenantId, string $roleCode): User
    {
        $this->seed([
            \Database\Seeders\TenantSeeder::class,
            \Database\Seeders\RbacCatalogSeeder::class,
        ]);

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
