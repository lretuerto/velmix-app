<?php

namespace Tests\Feature\Security;

use Tests\TestCase;

class TenantContextMiddlewareTest extends TestCase
{
    public function test_rejects_request_without_tenant_header(): void
    {
        $this->getJson('/tenant/ping')
            ->assertStatus(400)
            ->assertJson(['message' => 'Tenant context is required']);
    }

    public function test_allows_request_with_tenant_header(): void
    {
        $this->withHeaders(['X-Tenant-Id' => '10'])
            ->getJson('/tenant/ping')
            ->assertOk()
            ->assertJson([
                'ok' => true,
                'tenant' => '10'
            ]);
    }
}
