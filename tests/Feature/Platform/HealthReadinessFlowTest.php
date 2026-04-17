<?php

namespace Tests\Feature\Platform;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class HealthReadinessFlowTest extends TestCase
{
    use RefreshDatabase;

    public function test_live_and_ready_endpoints_expose_request_context_and_status(): void
    {
        $this->withHeader('X-Request-Id', 'health-probe-001')
            ->getJson('/health/live')
            ->assertOk()
            ->assertHeader('X-Request-Id', 'health-probe-001')
            ->assertJsonPath('data.status', 'live')
            ->assertJsonPath('data.request_id', 'health-probe-001');

        $this->getJson('/health/ready')
            ->assertOk()
            ->assertJsonPath('data.status', 'ready')
            ->assertJsonPath('data.checks.database.ok', true)
            ->assertJsonPath('data.checks.schema.ok', true)
            ->assertJsonMissingPath('data.checks.database.message')
            ->assertJsonMissingPath('data.checks.schema.required_tables')
            ->assertJsonMissingPath('data.checks.schema.missing_tables');
    }
}
