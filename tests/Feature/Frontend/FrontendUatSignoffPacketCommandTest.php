<?php

namespace Tests\Feature\Frontend;

use App\Services\Frontend\FrontendUatArtifactPaths;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

class FrontendUatSignoffPacketCommandTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        File::deleteDirectory(FrontendUatArtifactPaths::baseDirectory());
    }

    public function test_command_blocks_when_smoke_evidence_is_missing(): void
    {
        $this->seed([
            \Database\Seeders\TenantSeeder::class,
            \Database\Seeders\RbacCatalogSeeder::class,
        ]);

        $this->artisan('frontend:seed-pos-smoke', [
            '--json' => true,
        ])->assertExitCode(0);

        $exitCode = Artisan::call('frontend:uat-signoff-pack', [
            '--json' => true,
        ]);
        $payload = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

        $this->assertSame(1, $exitCode);
        $this->assertSame('blocked', $payload['status']);
        $this->assertContains('smoke.evidence_not_passed', array_column($payload['blocked_items'], 'code'));
        $this->assertFileExists($payload['artifacts']['latest_markdown_path']);
        $this->assertFileExists($payload['artifacts']['latest_json_path']);
    }

    public function test_command_generates_ready_packet_after_readiness_and_smoke_pass(): void
    {
        $this->seed([
            \Database\Seeders\TenantSeeder::class,
            \Database\Seeders\RbacCatalogSeeder::class,
        ]);

        $this->artisan('frontend:seed-pos-smoke', [
            '--json' => true,
        ])->assertExitCode(0);

        $this->artisan('frontend:pos-quote-first-uat-smoke', [
            '--json' => true,
        ])->assertExitCode(0);

        $exitCode = Artisan::call('frontend:uat-signoff-pack', [
            '--base-url' => 'http://127.0.0.1:8010',
            '--json' => true,
        ]);
        $payload = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

        $this->assertSame(0, $exitCode);
        $this->assertSame('ready_for_visual_signoff', $payload['status']);
        $this->assertSame('ready', $payload['readiness']['status']);
        $this->assertSame('passed', $payload['smoke']['status']);
        $this->assertSame('http://127.0.0.1:8010', $payload['base_url']);
        $this->assertSame('ok', $payload['modules']['pos']['status']);
        $this->assertSame([], $payload['blocked_items']);
        $this->assertFileExists($payload['artifacts']['latest_markdown_path']);
        $this->assertFileExists($payload['artifacts']['latest_json_path']);
        $this->assertStringContainsString('Frontend UAT Signoff Packet', File::get($payload['artifacts']['latest_markdown_path']));
        $this->assertStringContainsString('Firma visual pendiente', File::get($payload['artifacts']['latest_markdown_path']));

        $this->assertSame(4, DB::table('sales')->where('tenant_id', 10)->count());
    }
}
