<?php

namespace Tests\Feature\Frontend;

use App\Services\Frontend\FrontendUatArtifactPaths;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

class FrontendUatVisualEvidenceCommandTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        File::deleteDirectory(FrontendUatArtifactPaths::baseDirectory());
    }

    public function test_template_blocks_without_ready_signoff_packet(): void
    {
        $exitCode = Artisan::call('frontend:uat-visual-evidence-template', [
            '--json' => true,
        ]);
        $payload = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

        $this->assertSame(1, $exitCode);
        $this->assertSame('blocked', $payload['status']);
        $this->assertStringContainsString('Missing frontend UAT signoff packet', $payload['reason']);
    }

    public function test_template_generates_visual_evidence_manifest_after_ready_packet(): void
    {
        $this->prepareReadySignoffPacket();

        $exitCode = Artisan::call('frontend:uat-visual-evidence-template', [
            '--json' => true,
        ]);
        $payload = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

        $this->assertSame(0, $exitCode);
        $this->assertSame('draft', $payload['status']);
        $this->assertSame('ready_for_visual_signoff', $payload['packet']['status']);
        $this->assertArrayHasKey('pos', $payload['modules']);
        $this->assertArrayHasKey('cash', $payload['modules']);
        $this->assertArrayHasKey('receivables', $payload['modules']);
        $this->assertArrayHasKey('business_owner', $payload['final_approvals']);
        $this->assertFileExists($payload['artifacts']['latest_json_path']);
        $this->assertFileExists($payload['artifacts']['latest_markdown_path']);
    }

    public function test_verify_blocks_when_manifest_is_incomplete(): void
    {
        $this->prepareReadySignoffPacket();
        $this->artisan('frontend:uat-visual-evidence-template', [
            '--json' => true,
        ])->assertExitCode(0);

        $exitCode = Artisan::call('frontend:uat-visual-evidence-verify', [
            '--json' => true,
        ]);
        $payload = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

        $this->assertSame(1, $exitCode);
        $this->assertSame('blocked', $payload['status']);
        $this->assertNotEmpty($payload['blocked_items']);
        $this->assertContains('module.decision_missing', array_column($payload['blocked_items'], 'code'));
        $this->assertContains('approval.decision_missing', array_column($payload['blocked_items'], 'code'));
        $this->assertFileExists($payload['artifacts']['latest_json_path']);
        $this->assertFileExists($payload['artifacts']['latest_markdown_path']);
    }

    public function test_verify_marks_signed_when_manifest_has_module_evidence_and_approvals(): void
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

        $exitCode = Artisan::call('frontend:uat-visual-evidence-verify', [
            '--json' => true,
        ]);
        $payload = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

        $this->assertSame(0, $exitCode);
        $this->assertSame('signed', $payload['status']);
        $this->assertSame([], $payload['blocked_items']);
        $this->assertFileExists($payload['artifacts']['latest_json_path']);
        $this->assertFileExists($payload['artifacts']['latest_markdown_path']);
        $this->assertStringContainsString('Firma visual completa', File::get($payload['artifacts']['latest_markdown_path']));
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
            $manifest['modules'][$moduleCode]['approved_at'] = '2026-05-08T15:30:00Z';
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
            $manifest['final_approvals'][$approvalCode]['signed_at'] = '2026-05-08T16:00:00Z';
            $manifest['final_approvals'][$approvalCode]['signature'] = 'signed://'.$approvalCode;
        }

        $manifest['status'] = 'submitted';

        return $manifest;
    }
}
