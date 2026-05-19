<?php

namespace Tests\Feature\Frontend;

use App\Services\Frontend\FrontendUatArtifactPaths;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

class FrontendUatReleaseReadinessCommandTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        File::deleteDirectory(FrontendUatArtifactPaths::baseDirectory());
    }

    public function test_command_blocks_when_frontend_uat_evidence_is_missing(): void
    {
        $exitCode = Artisan::call('frontend:uat-release-readiness', [
            '--json' => true,
        ]);
        $payload = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

        $this->assertSame(1, $exitCode);
        $this->assertSame('blocked', $payload['status']);
        $this->assertContains('frontend_uat_smoke_missing', array_column($payload['items'], 'code'));
        $this->assertContains('frontend_uat_signoff_packet_missing', array_column($payload['items'], 'code'));
        $this->assertContains('frontend_uat_visual_signoff_missing', array_column($payload['items'], 'code'));
    }

    public function test_command_blocks_when_visual_signoff_is_not_signed(): void
    {
        $this->prepareReadyPacketWithBlockedVisualSignoff();

        $exitCode = Artisan::call('frontend:uat-release-readiness', [
            '--json' => true,
        ]);
        $payload = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

        $this->assertSame(1, $exitCode);
        $this->assertSame('blocked', $payload['status']);
        $this->assertSame('passed', $payload['evidence']['smoke']['status']);
        $this->assertSame('ready_for_visual_signoff', $payload['evidence']['signoff_packet']['status']);
        $this->assertSame('blocked', $payload['evidence']['visual_signoff']['status']);
        $this->assertContains('frontend_uat_visual_signoff_not_signed', array_column($payload['items'], 'code'));
        $this->assertContains('frontend_uat_visual_signoff_has_blockers', array_column($payload['items'], 'code'));
    }

    public function test_command_marks_ready_for_release_when_all_frontend_uat_evidence_is_signed(): void
    {
        $this->prepareReadyPacketWithSignedVisualSignoff();

        $exitCode = Artisan::call('frontend:uat-release-readiness', [
            '--json' => true,
        ]);
        $payload = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

        $this->assertSame(0, $exitCode);
        $this->assertSame('ready_for_release', $payload['status']);
        $this->assertSame([], $payload['items']);
        $this->assertSame('passed', $payload['evidence']['smoke']['status']);
        $this->assertSame('ready_for_visual_signoff', $payload['evidence']['signoff_packet']['status']);
        $this->assertSame('signed', $payload['evidence']['visual_signoff']['status']);
    }

    public function test_command_blocks_when_signed_visual_evidence_is_stale(): void
    {
        $this->prepareReadyPacketWithSignedVisualSignoff();

        $exitCode = Artisan::call('frontend:uat-release-readiness', [
            '--freshness-hours' => 1,
            '--json' => true,
        ]);
        $payload = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

        $this->assertSame(0, $exitCode);
        $this->assertSame('ready_for_release', $payload['status']);

        $path = FrontendUatArtifactPaths::baseDirectory().'/signoff/frontend-uat-visual-signoff-latest.json';
        $signoff = json_decode(File::get($path), true, flags: JSON_THROW_ON_ERROR);
        $signoff['verified_at'] = now()->subHours(2)->toISOString();
        File::put($path, json_encode($signoff, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES).PHP_EOL);

        $exitCode = Artisan::call('frontend:uat-release-readiness', [
            '--freshness-hours' => 1,
            '--json' => true,
        ]);
        $payload = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

        $this->assertSame(1, $exitCode);
        $this->assertSame('blocked', $payload['status']);
        $this->assertContains('frontend_uat_visual_signoff_stale', array_column($payload['items'], 'code'));
    }

    private function prepareReadyPacketWithBlockedVisualSignoff(): void
    {
        $this->prepareReadySignoffPacket();

        $this->artisan('frontend:uat-visual-evidence-template', [
            '--json' => true,
        ])->assertExitCode(0);

        $this->artisan('frontend:uat-visual-evidence-verify', [
            '--json' => true,
        ])->assertExitCode(1);
    }

    private function prepareReadyPacketWithSignedVisualSignoff(): void
    {
        $this->prepareReadySignoffPacket();

        $templateExitCode = Artisan::call('frontend:uat-visual-evidence-template', [
            '--json' => true,
        ]);
        $template = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

        $this->assertSame(0, $templateExitCode);

        $manifest = $this->completeManifest($template);
        File::put(
            $template['artifacts']['latest_json_path'],
            json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES).PHP_EOL,
        );

        $this->artisan('frontend:uat-visual-evidence-verify', [
            '--json' => true,
        ])->assertExitCode(0);
    }

    private function prepareReadySignoffPacket(): void
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

        $this->artisan('frontend:uat-signoff-pack', [
            '--base-url' => 'http://127.0.0.1:8010',
            '--json' => true,
        ])->assertExitCode(0);
    }

    /**
     * @param  array<string, mixed>  $manifest
     * @return array<string, mixed>
     */
    private function completeManifest(array $manifest): array
    {
        foreach (array_keys($manifest['modules']) as $moduleCode) {
            $manifest['modules'][$moduleCode]['decision'] = 'approved';
            $manifest['modules'][$moduleCode]['approved_by'] = 'QA UAT';
            $manifest['modules'][$moduleCode]['approved_at'] = now()->toISOString();
            $manifest['modules'][$moduleCode]['screenshots'] = [
                'evidence://screenshots/'.$moduleCode.'.png',
            ];
            $manifest['modules'][$moduleCode]['network_captures'] = [
                'evidence://network/'.$moduleCode.'.har',
            ];
            $manifest['modules'][$moduleCode]['request_ids'] = [
                'req-'.$moduleCode.'-001',
            ];
        }

        foreach (array_keys($manifest['final_approvals']) as $approvalCode) {
            $manifest['final_approvals'][$approvalCode]['name'] = 'Firmante '.$approvalCode;
            $manifest['final_approvals'][$approvalCode]['decision'] = 'approved';
            $manifest['final_approvals'][$approvalCode]['signed_at'] = now()->toISOString();
            $manifest['final_approvals'][$approvalCode]['signature'] = 'signed://'.$approvalCode;
        }

        $manifest['status'] = 'submitted';

        return $manifest;
    }
}
