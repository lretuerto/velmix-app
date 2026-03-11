<?php

namespace Tests\Feature\Billing;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class BillingProviderProfileFlowTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_can_read_default_provider_profile_for_current_tenant(): void
    {
        $this->seedBaseCatalog();
        $admin = $this->seedBillingUser(10, 'ADMIN');

        $this->actingAs($admin)
            ->withHeader('X-Tenant-Id', '10')
            ->getJson('/billing/provider-profile')
            ->assertOk()
            ->assertJsonPath('data.tenant_id', 10)
            ->assertJsonPath('data.provider_code', 'fake_sunat')
            ->assertJsonPath('data.environment', 'sandbox')
            ->assertJsonPath('data.default_outcome', 'accepted')
            ->assertJsonPath('data.health_status', 'unknown')
            ->assertJsonPath('data.health_checked_at', null);
    }

    public function test_admin_can_update_provider_profile_and_dispatch_uses_it(): void
    {
        $this->seedBaseCatalog();
        $admin = $this->seedBillingUser(10, 'ADMIN');
        [$voucherId, $eventId] = $this->seedPendingVoucher(10, 1);

        $this->actingAs($admin)
            ->withHeader('X-Tenant-Id', '10')
            ->putJson('/billing/provider-profile', [
                'provider_code' => 'fake_sunat',
                'environment' => 'sandbox',
                'default_outcome' => 'rejected',
                'credentials' => ['endpoint' => 'sandbox'],
            ])
            ->assertOk()
            ->assertJsonPath('data.default_outcome', 'rejected')
            ->assertJsonPath('data.health_status', 'unknown');

        $this->actingAs($admin)
            ->withHeader('X-Tenant-Id', '10')
            ->postJson('/billing/outbox/dispatch')
            ->assertOk()
            ->assertJsonPath('data.document_id', $voucherId)
            ->assertJsonPath('data.status', 'rejected')
            ->assertJsonPath('data.provider_code', 'fake_sunat')
            ->assertJsonPath('data.provider_environment', 'sandbox');

        $this->assertDatabaseHas('electronic_vouchers', [
            'id' => $voucherId,
            'status' => 'rejected',
        ]);

        $this->assertDatabaseHas('outbox_attempts', [
            'outbox_event_id' => $eventId,
            'status' => 'rejected',
            'provider_code' => 'fake_sunat',
            'provider_environment' => 'sandbox',
        ]);

        $this->assertDatabaseHas('tenant_activity_logs', [
            'tenant_id' => 10,
            'user_id' => $admin->id,
            'domain' => 'billing',
            'event_type' => 'billing.provider_profile.updated',
            'aggregate_type' => 'billing_provider_profile',
        ]);
    }

    public function test_cashier_cannot_manage_provider_profile(): void
    {
        $this->seedBaseCatalog();
        $cashier = $this->seedBillingUser(10, 'CAJERO');

        $this->actingAs($cashier)
            ->withHeader('X-Tenant-Id', '10')
            ->getJson('/billing/provider-profile')
            ->assertStatus(403);

        $this->actingAs($cashier)
            ->withHeader('X-Tenant-Id', '10')
            ->putJson('/billing/provider-profile', [
                'default_outcome' => 'rejected',
            ])
            ->assertStatus(403);

        $this->actingAs($cashier)
            ->withHeader('X-Tenant-Id', '10')
            ->postJson('/billing/provider-profile/check')
            ->assertStatus(403);
    }

    public function test_admin_can_check_provider_health_and_persist_snapshot(): void
    {
        $this->seedBaseCatalog();
        $admin = $this->seedBillingUser(10, 'ADMIN');

        $this->actingAs($admin)
            ->withHeader('X-Tenant-Id', '10')
            ->postJson('/billing/provider-profile/check')
            ->assertOk()
            ->assertJsonPath('data.provider_code', 'fake_sunat')
            ->assertJsonPath('data.environment', 'sandbox')
            ->assertJsonPath('data.health_status', 'healthy')
            ->assertJsonPath('data.capabilities.simulated', true);

        $this->assertDatabaseHas('billing_provider_profiles', [
            'tenant_id' => 10,
            'provider_code' => 'fake_sunat',
            'health_status' => 'healthy',
        ]);

        $this->assertDatabaseHas('tenant_activity_logs', [
            'tenant_id' => 10,
            'user_id' => $admin->id,
            'domain' => 'billing',
            'event_type' => 'billing.provider_health.checked',
            'aggregate_type' => 'billing_provider_profile',
        ]);
    }

    private function seedBaseCatalog(): void
    {
        $this->seed([
            \Database\Seeders\TenantSeeder::class,
            \Database\Seeders\RbacCatalogSeeder::class,
        ]);
    }

    private function seedBillingUser(int $tenantId, string $roleCode): User
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

    private function seedPendingVoucher(int $tenantId, int $number): array
    {
        $saleId = DB::table('sales')->insertGetId([
            'tenant_id' => $tenantId,
            'user_id' => User::factory()->create()->id,
            'reference' => sprintf('SALE-PROVIDER-%d-%d', $tenantId, $number),
            'status' => 'completed',
            'total_amount' => 50.00,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $voucherId = DB::table('electronic_vouchers')->insertGetId([
            'tenant_id' => $tenantId,
            'sale_id' => $saleId,
            'type' => 'boleta',
            'series' => 'B001',
            'number' => $number,
            'status' => 'pending',
            'sunat_ticket' => null,
            'rejection_reason' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $eventId = DB::table('outbox_events')->insertGetId([
            'tenant_id' => $tenantId,
            'aggregate_type' => 'electronic_voucher',
            'aggregate_id' => $voucherId,
            'event_type' => 'voucher.created',
            'payload' => json_encode(['voucher_id' => $voucherId], JSON_THROW_ON_ERROR),
            'status' => 'pending',
            'retry_count' => 0,
            'last_error' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return [$voucherId, $eventId];
    }
}
