<?php

namespace Tests\Feature\Frontend;

use App\Services\Frontend\FrontendUatArtifactPaths;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

class FrontendUatReleaseClosureCommandTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        File::deleteDirectory(FrontendUatArtifactPaths::baseDirectory());
    }

    public function test_command_blocks_when_signed_frontend_uat_evidence_is_missing(): void
    {
        config([
            'velmix.frontend_uat_release_gate.enabled' => true,
            'velmix.frontend_uat_release_gate.required_environments' => ['testing'],
            'velmix.frontend_uat_release_gate.freshness_hours' => 24,
        ]);

        $exitCode = Artisan::call('frontend:uat-release-closure-pack', [
            '--json' => true,
        ]);
        $payload = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

        $this->assertSame(1, $exitCode);
        $this->assertSame('blocked', $payload['status']);
        $this->assertSame('no_go', $payload['go_no_go']['status']);
        $this->assertFalse($payload['go_no_go']['production_go_allowed']);
        $this->assertContains('frontend_uat_release_readiness_blocked', array_column($payload['blocked_items'], 'code'));
        $this->assertContains('system_preflight_not_ok', array_column($payload['blocked_items'], 'code'));
        $this->assertContains('visual_signoff_signed', array_column($payload['cutover_preconditions'], 'code'));
        $this->assertNotEmpty($payload['remediation_plan']);
        $this->assertFileExists($payload['artifacts']['latest_json_path']);
        $this->assertFileExists($payload['artifacts']['latest_markdown_path']);
    }

    public function test_command_blocks_when_gate_is_disabled_even_if_allowing_local_preflight(): void
    {
        $this->prepareSignedFrontendUatReleaseEvidence();

        config([
            'velmix.frontend_uat_release_gate.enabled' => false,
            'velmix.frontend_uat_release_gate.required_environments' => [],
        ]);

        $exitCode = Artisan::call('frontend:uat-release-closure-pack', [
            '--json' => true,
        ]);
        $payload = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

        $this->assertSame(1, $exitCode);
        $this->assertSame('blocked', $payload['status']);
        $this->assertSame('no_go', $payload['go_no_go']['status']);
        $this->assertContains('frontend_uat_release_gate_not_enabled', array_column($payload['blocked_items'], 'code'));
    }

    public function test_command_blocks_observability_critical_by_default_after_signed_evidence(): void
    {
        $this->prepareSignedFrontendUatReleaseEvidence();

        config([
            'velmix.frontend_uat_release_gate.enabled' => true,
            'velmix.frontend_uat_release_gate.required_environments' => ['testing'],
            'velmix.frontend_uat_release_gate.freshness_hours' => 24,
        ]);

        $exitCode = Artisan::call('frontend:uat-release-closure-pack', [
            '--json' => true,
        ]);
        $payload = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

        $this->assertSame(1, $exitCode);
        $this->assertSame('blocked', $payload['status']);
        $this->assertSame('no_go', $payload['go_no_go']['status']);
        $this->assertContains('system_observability_critical', array_column($payload['blocked_items'], 'code'));
        $this->assertSame('ready_for_release', $payload['frontend_uat_release_readiness']['status']);
        $this->assertSame('ok', $payload['system_preflight']['status']);
        $this->assertFileExists($payload['artifacts']['latest_json_path']);
        $this->assertFileExists($payload['artifacts']['latest_markdown_path']);
        $this->assertStringContainsString('Frontend UAT Release Closure Packet', File::get($payload['artifacts']['latest_markdown_path']));
    }

    public function test_command_can_allow_gate_disabled_for_local_dry_run(): void
    {
        $this->prepareSignedFrontendUatReleaseEvidence();

        config([
            'velmix.frontend_uat_release_gate.enabled' => false,
            'velmix.frontend_uat_release_gate.required_environments' => [],
        ]);

        $exitCode = Artisan::call('frontend:uat-release-closure-pack', [
            '--allow-gate-disabled' => true,
            '--allow-observability-critical' => true,
            '--decision-owner' => 'Luis Retuerto',
            '--decision-ticket' => 'UAT-FE-001',
            '--decision-notes' => 'Visual UAT approved by business owner.',
            '--json' => true,
        ]);
        $payload = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

        $this->assertSame(0, $exitCode, json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        $this->assertSame('ready_for_release_closure', $payload['status']);
        $this->assertSame('no_go', $payload['go_no_go']['status']);
        $this->assertFalse($payload['go_no_go']['production_go_allowed']);
        $this->assertTrue($payload['go_no_go']['uat_dry_run_allowed']);
        $this->assertTrue($payload['go_no_go']['override_present']);
        $this->assertTrue($payload['allow_gate_disabled']);
        $this->assertTrue($payload['allow_observability_critical']);
        $this->assertSame([], $payload['blocked_items']);
        $this->assertSame([], $payload['remediation_plan']);
        $this->assertSame('Luis Retuerto', $payload['decision']['owner']);
        $this->assertNotEmpty($payload['decision']['decided_at']);
        $this->assertSame('UAT-FE-001', $payload['decision']['ticket']);
        $this->assertSame('Visual UAT approved by business owner.', $payload['decision']['notes']);
        $this->assertStringContainsString('Luis Retuerto', File::get($payload['artifacts']['latest_markdown_path']));
    }

    private function prepareSignedFrontendUatReleaseEvidence(): void
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

        $templateExitCode = Artisan::call('frontend:uat-visual-evidence-template', [
            '--json' => true,
        ]);
        $template = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

        $this->assertSame(0, $templateExitCode);

        foreach (array_keys($template['modules']) as $moduleCode) {
            $template['modules'][$moduleCode]['decision'] = 'approved';
            $template['modules'][$moduleCode]['approved_by'] = 'QA UAT';
            $template['modules'][$moduleCode]['approved_at'] = now()->toISOString();
            $template['modules'][$moduleCode]['screenshots'] = [
                'evidence://screenshots/'.$moduleCode.'.png',
            ];
            $template['modules'][$moduleCode]['network_captures'] = [
                'evidence://network/'.$moduleCode.'.har',
            ];
            $template['modules'][$moduleCode]['request_ids'] = [
                'req-'.$moduleCode.'-001',
            ];
        }

        foreach (array_keys($template['final_approvals']) as $approvalCode) {
            $template['final_approvals'][$approvalCode]['name'] = 'Firmante '.$approvalCode;
            $template['final_approvals'][$approvalCode]['decision'] = 'approved';
            $template['final_approvals'][$approvalCode]['signed_at'] = now()->toISOString();
            $template['final_approvals'][$approvalCode]['signature'] = 'signed://'.$approvalCode;
        }

        $template['status'] = 'submitted';

        File::put(
            $template['artifacts']['latest_json_path'],
            json_encode($template, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES).PHP_EOL,
        );

        $this->artisan('frontend:uat-visual-evidence-verify', [
            '--json' => true,
        ])->assertExitCode(0);
    }
}
