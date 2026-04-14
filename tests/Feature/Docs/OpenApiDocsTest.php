<?php

namespace Tests\Feature\Docs;

use App\Models\ApiToken;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class OpenApiDocsTest extends TestCase
{
    use RefreshDatabase;

    public function test_docs_endpoints_require_authentication(): void
    {
        $this->getJson('/docs')->assertStatus(401);
        $this->get('/docs/openapi.yaml')->assertStatus(401);
        $this->get('/docs/openapi.yaml', ['Accept' => 'application/json'])->assertStatus(401);
        $this->get('/docs/api-guide', ['Accept' => 'application/json'])->assertStatus(401);
        $this->get('/docs/release-readiness', ['Accept' => 'application/json'])->assertStatus(401);
    }

    public function test_docs_endpoints_require_tenant_context_for_authenticated_session(): void
    {
        $this->seed([
            \Database\Seeders\TenantSeeder::class,
            \Database\Seeders\RbacCatalogSeeder::class,
        ]);

        $user = $this->seedTenantAdminUser(10);

        $this->actingAs($user)
            ->getJson('/docs')
            ->assertStatus(400)
            ->assertJsonPath('message', 'Tenant context is required');
    }

    public function test_docs_endpoints_do_not_accept_bearer_tokens(): void
    {
        $this->seed(\Database\Seeders\TenantSeeder::class);

        $user = User::factory()->create();
        $plainTextToken = Str::random(64);

        ApiToken::query()->create([
            'tenant_id' => 10,
            'user_id' => $user->id,
            'name' => 'Docs probe',
            'token_prefix' => substr($plainTextToken, 0, 12),
            'token_hash' => hash('sha256', $plainTextToken),
            'abilities' => ['*'],
        ]);

        $this->withToken($plainTextToken)
            ->getJson('/docs')
            ->assertStatus(401);

        $this->withToken($plainTextToken)
            ->get('/docs/openapi.yaml', ['Accept' => 'application/json'])
            ->assertStatus(401);
    }

    public function test_tenant_member_without_docs_permission_cannot_read_docs(): void
    {
        $this->seed([
            \Database\Seeders\TenantSeeder::class,
            \Database\Seeders\RbacCatalogSeeder::class,
        ]);

        $user = $this->seedTenantUser(10);

        $this->actingAs($user)
            ->withHeader('X-Tenant-Id', '10')
            ->getJson('/docs')
            ->assertStatus(403);
    }

    public function test_exposes_docs_index_with_expected_documents(): void
    {
        $this->seed([
            \Database\Seeders\TenantSeeder::class,
            \Database\Seeders\RbacCatalogSeeder::class,
        ]);

        $user = $this->seedTenantAdminUser(10);

        $this->actingAs($user)
            ->withHeader('X-Tenant-Id', '10')
            ->getJson('/docs')
            ->assertOk()
            ->assertJsonPath('data.project', 'VELMiX ERP')
            ->assertJsonFragment(['path' => '/docs/openapi.yaml'])
            ->assertJsonFragment(['path' => '/docs/api-guide'])
            ->assertJsonFragment(['path' => '/docs/release-readiness']);
    }

    public function test_serves_openapi_yaml_for_priority_endpoints(): void
    {
        $this->seed([
            \Database\Seeders\TenantSeeder::class,
            \Database\Seeders\RbacCatalogSeeder::class,
        ]);

        $user = $this->seedTenantAdminUser(10);
        $response = $this->actingAs($user)
            ->withHeader('X-Tenant-Id', '10')
            ->get('/docs/openapi.yaml');

        $response->assertOk();
        $this->assertStringContainsString('/health/live', $response->getContent());
        $this->assertStringContainsString('/health/ready', $response->getContent());
        $this->assertStringContainsString('/pos/sales', $response->getContent());
        $this->assertStringContainsString('/admin/team/roles', $response->getContent());
        $this->assertStringContainsString('/admin/team/users', $response->getContent());
        $this->assertStringContainsString('/billing/vouchers', $response->getContent());
        $this->assertStringContainsString('/billing/vouchers/{voucher}/payloads', $response->getContent());
        $this->assertStringContainsString('/billing/vouchers/{voucher}/payloads/regenerate', $response->getContent());
        $this->assertStringContainsString('/billing/vouchers/{voucher}/replay', $response->getContent());
        $this->assertStringContainsString('/billing/vouchers/{voucher}/reconcile', $response->getContent());
        $this->assertStringContainsString('/billing/provider-profile', $response->getContent());
        $this->assertStringContainsString('/billing/provider-profile/check', $response->getContent());
        $this->assertStringContainsString('/billing/provider-metrics', $response->getContent());
        $this->assertStringContainsString('/billing/credit-notes/{creditNote}/payloads', $response->getContent());
        $this->assertStringContainsString('/billing/credit-notes/{creditNote}/payloads/regenerate', $response->getContent());
        $this->assertStringContainsString('/billing/credit-notes/{creditNote}/replay', $response->getContent());
        $this->assertStringContainsString('/billing/credit-notes/{creditNote}/reconcile', $response->getContent());
        $this->assertStringContainsString('/billing/reconcile-pending', $response->getContent());
        $this->assertStringContainsString('/billing/outbox/{event}/lineage', $response->getContent());
        $this->assertStringContainsString('/billing/outbox/provider-trace', $response->getContent());
        $this->assertStringContainsString('/billing/outbox/summary', $response->getContent());
        $this->assertStringContainsString('/reports/operations-control-tower', $response->getContent());
        $this->assertStringContainsString('/reports/operations-control-tower/briefing', $response->getContent());
        $this->assertStringContainsString('/reports/operations-control-tower/briefing/export', $response->getContent());
        $this->assertStringContainsString('/reports/operations-control-tower/history', $response->getContent());
        $this->assertStringContainsString('/reports/operations-control-tower/compare', $response->getContent());
        $this->assertStringContainsString('/reports/operations-control-tower/snapshots', $response->getContent());
        $this->assertStringContainsString('/reports/operations-control-tower/snapshots/{snapshot}', $response->getContent());
        $this->assertStringContainsString('/reports/operations-control-tower/snapshots/{snapshot}/export', $response->getContent());
        $this->assertStringContainsString('/reports/operations-control-tower/snapshots/{snapshot}/compare', $response->getContent());
        $this->assertStringContainsString('/reports/operations-control-tower/snapshots/{snapshot}/compare/export', $response->getContent());
        $this->assertStringContainsString('/reports/billing-operations', $response->getContent());
        $this->assertStringContainsString('/reports/billing-escalations', $response->getContent());
        $this->assertStringContainsString('/reports/billing-escalation-metrics', $response->getContent());
        $this->assertStringContainsString('/reports/finance-operations', $response->getContent());
        $this->assertStringContainsString('/reports/finance-escalations', $response->getContent());
        $this->assertStringContainsString('/reports/finance-escalations/history', $response->getContent());
        $this->assertStringContainsString('/reports/finance-escalation-metrics', $response->getContent());
        $this->assertStringContainsString('/reports/finance-escalations/{code}', $response->getContent());
        $this->assertStringContainsString('/reports/finance-escalations/{code}/acknowledge', $response->getContent());
        $this->assertStringContainsString('/reports/finance-escalations/{code}/resolve', $response->getContent());
        $this->assertStringContainsString('/reports/operations-escalations', $response->getContent());
        $this->assertStringContainsString('/reports/operations-escalations/history', $response->getContent());
        $this->assertStringContainsString('/reports/operations-escalation-metrics', $response->getContent());
        $this->assertStringContainsString('/reports/operations-escalations/{domain}/{code}', $response->getContent());
        $this->assertStringContainsString('/reports/operations-escalations/{domain}/{code}/acknowledge', $response->getContent());
        $this->assertStringContainsString('/reports/operations-escalations/{domain}/{code}/resolve', $response->getContent());
        $this->assertStringContainsString('/reports/finance-operations/history', $response->getContent());
        $this->assertStringContainsString('/reports/finance-operations/metrics', $response->getContent());
        $this->assertStringContainsString('/reports/finance-operations/{kind}/{entity}', $response->getContent());
        $this->assertStringContainsString('/reports/finance-operations/{kind}/{entity}/history', $response->getContent());
        $this->assertStringContainsString('/reports/finance-operations/{kind}/{entity}/acknowledge', $response->getContent());
        $this->assertStringContainsString('/reports/finance-operations/{kind}/{entity}/resolve', $response->getContent());
        $this->assertStringContainsString('/reports/billing-escalations/history', $response->getContent());
        $this->assertStringContainsString('/reports/billing-escalations/{code}', $response->getContent());
        $this->assertStringContainsString('/reports/billing-escalations/{code}/acknowledge', $response->getContent());
        $this->assertStringContainsString('/reports/billing-escalations/{code}/resolve', $response->getContent());
        $this->assertStringContainsString('/audit/timeline', $response->getContent());
        $this->assertStringContainsString('/auth/tokens', $response->getContent());
        $this->assertStringContainsString('security.api-token.manage', $response->getContent());
        $this->assertStringContainsString('bearerAuth', $response->getContent());
        $this->assertStringContainsString('X-Tenant-Id', $response->getContent());
    }

    public function test_serves_api_guide_and_release_checklist(): void
    {
        $this->seed([
            \Database\Seeders\TenantSeeder::class,
            \Database\Seeders\RbacCatalogSeeder::class,
        ]);

        $user = $this->seedTenantAdminUser(10);

        $this->actingAs($user)
            ->withHeader('X-Tenant-Id', '10')
            ->get('/docs/api-guide')
            ->assertOk()
            ->assertSee('X-Tenant-Id', false)
            ->assertSee('security.docs.read', false)
            ->assertSee('security.api-token.manage', false)
            ->assertSee('no acepta bearer tokens', false)
            ->assertSee('Idempotency-Key', false)
            ->assertSee('POST /pos/sales', false)
            ->assertSee('GET /health/live', false)
            ->assertSee('GET /health/ready', false)
            ->assertSee('GET /admin/team/roles', false)
            ->assertSee('POST /admin/team/users', false)
            ->assertSee('GET /billing/provider-profile', false)
            ->assertSee('POST /billing/provider-profile/check', false)
            ->assertSee('GET /billing/provider-metrics', false)
            ->assertSee('GET /billing/vouchers/{voucher}/payloads', false)
            ->assertSee('POST /billing/vouchers/{voucher}/payloads/regenerate', false)
            ->assertSee('POST /billing/vouchers/{voucher}/replay', false)
            ->assertSee('POST /billing/vouchers/{voucher}/reconcile', false)
            ->assertSee('POST /billing/reconcile-pending', false)
            ->assertSee('GET /billing/outbox/{event}/lineage', false)
            ->assertSee('GET /billing/outbox/provider-trace', false)
            ->assertSee('GET /reports/operations-control-tower', false)
            ->assertSee('GET /reports/operations-control-tower/briefing', false)
            ->assertSee('GET /reports/operations-control-tower/briefing/export', false)
            ->assertSee('GET /reports/operations-control-tower/history', false)
            ->assertSee('GET /reports/operations-control-tower/compare', false)
            ->assertSee('POST /reports/operations-control-tower/snapshots', false)
            ->assertSee('GET /reports/operations-control-tower/snapshots', false)
            ->assertSee('GET /reports/operations-control-tower/snapshots/{snapshot}', false)
            ->assertSee('GET /reports/operations-control-tower/snapshots/{snapshot}/export', false)
            ->assertSee('GET /reports/operations-control-tower/snapshots/{snapshot}/compare', false)
            ->assertSee('GET /reports/operations-control-tower/snapshots/{snapshot}/compare/export', false)
            ->assertSee('GET /reports/billing-operations', false)
            ->assertSee('GET /reports/billing-escalations', false)
            ->assertSee('GET /reports/billing-escalation-metrics', false)
            ->assertSee('GET /reports/finance-operations', false)
            ->assertSee('GET /reports/finance-escalations', false)
            ->assertSee('GET /reports/finance-escalations/history', false)
            ->assertSee('GET /reports/finance-escalation-metrics', false)
            ->assertSee('GET /reports/finance-escalations/{code}', false)
            ->assertSee('POST /reports/finance-escalations/{code}/acknowledge', false)
            ->assertSee('POST /reports/finance-escalations/{code}/resolve', false)
            ->assertSee('GET /reports/operations-escalations', false)
            ->assertSee('GET /reports/operations-escalations/history', false)
            ->assertSee('GET /reports/operations-escalation-metrics', false)
            ->assertSee('GET /reports/operations-escalations/{domain}/{code}', false)
            ->assertSee('POST /reports/operations-escalations/{domain}/{code}/acknowledge', false)
            ->assertSee('POST /reports/operations-escalations/{domain}/{code}/resolve', false)
            ->assertSee('GET /reports/finance-operations/history', false)
            ->assertSee('GET /reports/finance-operations/metrics', false)
            ->assertSee('GET /reports/finance-operations/{kind}/{entity}', false)
            ->assertSee('GET /reports/finance-operations/{kind}/{entity}/history', false)
            ->assertSee('POST /reports/finance-operations/{kind}/{entity}/acknowledge', false)
            ->assertSee('POST /reports/finance-operations/{kind}/{entity}/resolve', false)
            ->assertSee('GET /reports/billing-escalations/history', false)
            ->assertSee('GET /reports/billing-escalations/{code}', false)
            ->assertSee('POST /reports/billing-escalations/{code}/acknowledge', false)
            ->assertSee('POST /reports/billing-escalations/{code}/resolve', false);

        $this->actingAs($user)
            ->withHeader('X-Tenant-Id', '10')
            ->get('/docs/release-readiness')
            ->assertOk()
            ->assertSee('composer run velmix:qa', false)
            ->assertSee('docs internas accesibles desde `/docs`', false);
    }

    private function seedTenantUser(int $tenantId): User
    {
        $user = User::factory()->create();

        DB::table('tenant_user')->insert([
            'tenant_id' => $tenantId,
            'user_id' => $user->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $user;
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
