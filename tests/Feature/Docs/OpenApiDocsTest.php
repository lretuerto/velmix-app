<?php

namespace Tests\Feature\Docs;

use Tests\TestCase;

class OpenApiDocsTest extends TestCase
{
    public function test_exposes_docs_index_with_expected_documents(): void
    {
        $this->getJson('/docs')
            ->assertOk()
            ->assertJsonPath('data.project', 'VELMiX ERP')
            ->assertJsonFragment(['path' => '/docs/openapi.yaml'])
            ->assertJsonFragment(['path' => '/docs/api-guide'])
            ->assertJsonFragment(['path' => '/docs/release-readiness']);
    }

    public function test_serves_openapi_yaml_for_priority_endpoints(): void
    {
        $response = $this->get('/docs/openapi.yaml');

        $response->assertOk();
        $this->assertStringContainsString('/pos/sales', $response->getContent());
        $this->assertStringContainsString('/billing/vouchers', $response->getContent());
        $this->assertStringContainsString('/billing/vouchers/{voucher}/payloads', $response->getContent());
        $this->assertStringContainsString('/billing/vouchers/{voucher}/payloads/regenerate', $response->getContent());
        $this->assertStringContainsString('/billing/vouchers/{voucher}/replay', $response->getContent());
        $this->assertStringContainsString('/billing/provider-profile', $response->getContent());
        $this->assertStringContainsString('/billing/provider-profile/check', $response->getContent());
        $this->assertStringContainsString('/billing/provider-metrics', $response->getContent());
        $this->assertStringContainsString('/billing/credit-notes/{creditNote}/payloads', $response->getContent());
        $this->assertStringContainsString('/billing/credit-notes/{creditNote}/payloads/regenerate', $response->getContent());
        $this->assertStringContainsString('/billing/credit-notes/{creditNote}/replay', $response->getContent());
        $this->assertStringContainsString('/billing/outbox/{event}/lineage', $response->getContent());
        $this->assertStringContainsString('/billing/outbox/provider-trace', $response->getContent());
        $this->assertStringContainsString('/billing/outbox/summary', $response->getContent());
        $this->assertStringContainsString('/reports/billing-operations', $response->getContent());
        $this->assertStringContainsString('/audit/timeline', $response->getContent());
        $this->assertStringContainsString('/auth/tokens', $response->getContent());
        $this->assertStringContainsString('bearerAuth', $response->getContent());
        $this->assertStringContainsString('X-Tenant-Id', $response->getContent());
    }

    public function test_serves_api_guide_and_release_checklist(): void
    {
        $this->get('/docs/api-guide')
            ->assertOk()
            ->assertSee('X-Tenant-Id', false)
            ->assertSee('POST /pos/sales', false)
            ->assertSee('GET /billing/provider-profile', false)
            ->assertSee('POST /billing/provider-profile/check', false)
            ->assertSee('GET /billing/provider-metrics', false)
            ->assertSee('GET /billing/vouchers/{voucher}/payloads', false)
            ->assertSee('POST /billing/vouchers/{voucher}/payloads/regenerate', false)
            ->assertSee('POST /billing/vouchers/{voucher}/replay', false)
            ->assertSee('GET /billing/outbox/{event}/lineage', false)
            ->assertSee('GET /billing/outbox/provider-trace', false)
            ->assertSee('GET /reports/billing-operations', false);

        $this->get('/docs/release-readiness')
            ->assertOk()
            ->assertSee('composer run velmix:qa', false)
            ->assertSee('docs internas accesibles desde `/docs`', false);
    }
}
