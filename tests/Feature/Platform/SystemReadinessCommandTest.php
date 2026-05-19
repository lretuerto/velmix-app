<?php

namespace Tests\Feature\Platform;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

class SystemReadinessCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_system_readiness_command_reports_ready_state(): void
    {
        $exitCode = Artisan::call('system:readiness', [
            '--json' => true,
        ]);

        $this->assertSame(0, $exitCode);
        $this->assertStringContainsString('"status": "ready"', Artisan::output());
    }
}
