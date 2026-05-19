<?php

namespace Tests\Feature\Frontend;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

class FrontendUatReadinessCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_command_blocks_when_smoke_fixture_is_missing(): void
    {
        $this->seed([
            \Database\Seeders\TenantSeeder::class,
            \Database\Seeders\RbacCatalogSeeder::class,
        ]);

        $exitCode = Artisan::call('frontend:uat-readiness', [
            '--json' => true,
        ]);
        $payload = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

        $this->assertSame(1, $exitCode);
        $this->assertSame('blocked', $payload['status']);
        $this->assertContains('product.regular.ready', array_column($payload['items'], 'code'));
        $this->assertContains('operator.exists', array_column($payload['items'], 'code'));
    }

    public function test_command_passes_after_idempotent_pos_fixture_seed(): void
    {
        $this->seed([
            \Database\Seeders\TenantSeeder::class,
            \Database\Seeders\RbacCatalogSeeder::class,
        ]);

        $this->artisan('frontend:seed-pos-smoke', [
            '--json' => true,
        ])->assertExitCode(0);

        $exitCode = Artisan::call('frontend:uat-readiness', [
            '--json' => true,
        ]);
        $payload = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

        $this->assertSame(0, $exitCode);
        $this->assertSame('ready', $payload['status']);
        $this->assertSame('ok', $payload['modules']['session']['status']);
        $this->assertSame('ok', $payload['modules']['pos']['status']);
        $this->assertSame('ok', $payload['modules']['cash']['status']);
        $this->assertSame('ok', $payload['modules']['receivables']['status']);
        $this->assertSame('ok', $payload['modules']['catalog']['status']);
        $this->assertSame('ok', $payload['modules']['customers']['status']);
        $this->assertSame('/app/login?tenant=botica-central&redirect=/pos/sales', $payload['artifacts']['login_path']);
        $this->assertSame([], $payload['items']);
        $this->assertContains('CAJERO', $payload['operator']['roles']);
    }
}
