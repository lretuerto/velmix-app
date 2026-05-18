<?php

namespace Tests\Feature\Platform;

use App\Models\User;
use App\Services\Frontend\FrontendUatArtifactPaths;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

class SystemPreflightCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_system_preflight_command_reports_ok_under_default_local_configuration(): void
    {
        $exitCode = Artisan::call('system:preflight', [
            '--json' => true,
        ]);

        $output = json_decode(Artisan::output(), true, 512, JSON_THROW_ON_ERROR);

        $this->assertSame(0, $exitCode);
        $this->assertSame('ok', $output['status']);
        $this->assertSame('ready', $output['checks']['readiness']['status']);
        $this->assertSame('ok', $output['checks']['platform_safety']['status']);
        $this->assertSame('ok', $output['checks']['frontend_uat_release_gate']['status']);
        $this->assertFalse($output['checks']['frontend_uat_release_gate']['enabled']);
        $this->assertFalse($output['checks']['frontend_uat_release_gate']['required']);
        $this->assertSame([], $output['items']);
    }

    public function test_system_preflight_command_fails_on_warning_for_unsafe_scheduler_lock_store(): void
    {
        config([
            'velmix.scheduler.on_one_server' => true,
            'cache.default' => 'file',
        ]);

        $exitCode = Artisan::call('system:preflight', [
            '--json' => true,
            '--fail-on-warning' => true,
        ]);

        $output = json_decode(Artisan::output(), true, 512, JSON_THROW_ON_ERROR);

        $this->assertSame(1, $exitCode);
        $this->assertSame('warning', $output['status']);
        $this->assertContains('scheduler_lock_store_not_shared', array_column($output['items'], 'code'));
    }

    public function test_system_preflight_command_fails_on_critical_for_missing_queue_connection(): void
    {
        config([
            'queue.default' => 'missing-queue',
        ]);

        $exitCode = Artisan::call('system:preflight', [
            '--json' => true,
            '--fail-on-critical' => true,
        ]);

        $output = json_decode(Artisan::output(), true, 512, JSON_THROW_ON_ERROR);

        $this->assertSame(1, $exitCode);
        $this->assertSame('critical', $output['status']);
        $this->assertContains('queue_connection_missing', array_column($output['items'], 'code'));
    }

    public function test_system_preflight_command_warns_when_structured_logging_is_not_enabled_in_production_like_env(): void
    {
        config([
            'app.env' => 'production',
            'app.debug' => false,
            'logging.default' => 'stack',
            'logging.channels.stack.channels' => ['single'],
        ]);

        $exitCode = Artisan::call('system:preflight', [
            '--json' => true,
            '--fail-on-warning' => true,
        ]);

        $output = json_decode(Artisan::output(), true, 512, JSON_THROW_ON_ERROR);

        $this->assertSame(1, $exitCode);
        $this->assertSame('warning', $output['status']);
        $this->assertContains('structured_logging_not_enabled', array_column($output['items'], 'code'));
    }

    public function test_system_preflight_command_fails_when_required_writable_path_is_missing(): void
    {
        $storagePath = storage_path();
        $tempStoragePath = storage_path('framework/testing/preflight-missing-logs');

        File::deleteDirectory($tempStoragePath);
        File::ensureDirectoryExists($tempStoragePath);
        $this->app->useStoragePath($tempStoragePath);

        try {
            $exitCode = Artisan::call('system:preflight', [
                '--json' => true,
                '--fail-on-critical' => true,
            ]);

            $output = json_decode(Artisan::output(), true, 512, JSON_THROW_ON_ERROR);

            $this->assertSame(1, $exitCode);
            $this->assertSame('critical', $output['status']);
            $this->assertContains('writable_path_missing', array_column($output['items'], 'code'));
        } finally {
            $this->app->useStoragePath($storagePath);
            File::deleteDirectory($tempStoragePath);
        }
    }

    public function test_system_preflight_command_fails_on_critical_when_backup_encryption_is_missing(): void
    {
        $root = storage_path('framework/testing/preflight-backup-critical');
        File::deleteDirectory($root);
        File::ensureDirectoryExists($root.'/backups/history');
        File::ensureDirectoryExists($root.'/restore-drills');

        config([
            'app.env' => 'production',
            'app.debug' => false,
            'velmix.backup.enabled' => true,
            'velmix.backup.storage_path' => $root.'/backups',
            'velmix.backup.history_path' => $root.'/backups/history',
            'velmix.backup.restore_drill_path' => $root.'/restore-drills',
            'velmix.backup.encryption_passphrase' => null,
        ]);

        try {
            $exitCode = Artisan::call('system:preflight', [
                '--json' => true,
                '--fail-on-critical' => true,
            ]);

            $output = json_decode(Artisan::output(), true, 512, JSON_THROW_ON_ERROR);

            $this->assertSame(1, $exitCode);
            $this->assertSame('critical', $output['status']);
            $this->assertContains('backup_encryption_passphrase_missing', array_column($output['items'], 'code'));
        } finally {
            File::deleteDirectory($root);
        }
    }

    public function test_system_preflight_warns_when_required_cash_ledger_audit_is_disabled(): void
    {
        config([
            'velmix.cash_ledger_audit.enabled' => false,
            'velmix.cash_ledger_audit.required_environments' => ['testing'],
        ]);

        $exitCode = Artisan::call('system:preflight', [
            '--json' => true,
            '--fail-on-warning' => true,
        ]);

        $output = json_decode(Artisan::output(), true, 512, JSON_THROW_ON_ERROR);

        $this->assertSame(1, $exitCode);
        $this->assertSame('warning', $output['status']);
        $this->assertSame('warning', $output['checks']['cash_ledger']['status']);
        $this->assertContains('cash_ledger_audit_disabled', array_column($output['items'], 'code'));
    }

    public function test_system_preflight_warns_when_required_frontend_uat_release_gate_is_disabled(): void
    {
        config([
            'velmix.frontend_uat_release_gate.enabled' => false,
            'velmix.frontend_uat_release_gate.required_environments' => ['testing'],
        ]);

        $exitCode = Artisan::call('system:preflight', [
            '--json' => true,
            '--fail-on-warning' => true,
        ]);

        $output = json_decode(Artisan::output(), true, 512, JSON_THROW_ON_ERROR);

        $this->assertSame(1, $exitCode);
        $this->assertSame('warning', $output['status']);
        $this->assertSame('warning', $output['checks']['frontend_uat_release_gate']['status']);
        $this->assertContains('frontend_uat_release_gate_disabled', array_column($output['items'], 'code'));
    }

    public function test_system_preflight_fails_when_enabled_frontend_uat_release_gate_is_missing_evidence(): void
    {
        File::deleteDirectory(FrontendUatArtifactPaths::baseDirectory());

        config([
            'velmix.frontend_uat_release_gate.enabled' => true,
            'velmix.frontend_uat_release_gate.required_environments' => ['testing'],
            'velmix.frontend_uat_release_gate.freshness_hours' => 24,
        ]);

        $exitCode = Artisan::call('system:preflight', [
            '--json' => true,
            '--fail-on-critical' => true,
        ]);

        $output = json_decode(Artisan::output(), true, 512, JSON_THROW_ON_ERROR);

        $this->assertSame(1, $exitCode);
        $this->assertSame('critical', $output['status']);
        $this->assertSame('critical', $output['checks']['frontend_uat_release_gate']['status']);
        $this->assertContains('frontend_uat_release_not_ready', array_column($output['items'], 'code'));
    }

    public function test_system_preflight_passes_when_enabled_frontend_uat_release_gate_is_signed(): void
    {
        File::deleteDirectory(FrontendUatArtifactPaths::baseDirectory());
        $this->prepareSignedFrontendUatReleaseEvidence();

        config([
            'velmix.frontend_uat_release_gate.enabled' => true,
            'velmix.frontend_uat_release_gate.required_environments' => ['testing'],
            'velmix.frontend_uat_release_gate.freshness_hours' => 24,
        ]);

        $exitCode = Artisan::call('system:preflight', [
            '--json' => true,
            '--fail-on-critical' => true,
        ]);

        $output = json_decode(Artisan::output(), true, 512, JSON_THROW_ON_ERROR);

        $this->assertSame(0, $exitCode);
        $this->assertSame('ok', $output['status']);
        $this->assertSame('ok', $output['checks']['frontend_uat_release_gate']['status']);
        $this->assertSame('ready_for_release', $output['checks']['frontend_uat_release_gate']['readiness']['status']);
    }

    public function test_system_preflight_fails_when_enabled_cash_ledger_audit_finds_issues(): void
    {
        $this->seed(\Database\Seeders\TenantSeeder::class);
        $user = User::factory()->create();
        $openedAt = now()->subHours(2);
        $sessionId = (int) DB::table('cash_sessions')->insertGetId([
            'tenant_id' => 10,
            'opened_by_user_id' => $user->id,
            'closed_by_user_id' => $user->id,
            'opening_amount' => 100.00,
            'expected_amount' => 100.00,
            'counted_amount' => 100.00,
            'discrepancy_amount' => 0.00,
            'status' => 'closed',
            'open_guard' => null,
            'opened_at' => $openedAt,
            'closed_at' => $openedAt->copy()->addHour(),
            'created_at' => $openedAt,
            'updated_at' => $openedAt->copy()->addHour(),
        ]);

        DB::table('sales')->insert([
            'tenant_id' => 10,
            'user_id' => $user->id,
            'customer_id' => null,
            'cash_session_id' => $sessionId,
            'reference' => 'PREFLIGHT-CASH-LEDGER-MISSING',
            'status' => 'completed',
            'payment_method' => 'cash',
            'total_amount' => 20.00,
            'gross_cost' => 0.00,
            'gross_margin' => 20.00,
            'created_at' => $openedAt->copy()->addMinutes(10),
            'updated_at' => $openedAt->copy()->addMinutes(10),
        ]);

        config([
            'velmix.cash_ledger_audit.enabled' => true,
            'velmix.cash_ledger_audit.required_environments' => ['testing'],
            'velmix.cash_ledger_audit.tenant_id' => 10,
            'velmix.cash_ledger_audit.issue_limit' => 10,
        ]);

        $exitCode = Artisan::call('system:preflight', [
            '--json' => true,
            '--fail-on-critical' => true,
        ]);

        $output = json_decode(Artisan::output(), true, 512, JSON_THROW_ON_ERROR);

        $this->assertSame(1, $exitCode);
        $this->assertSame('critical', $output['status']);
        $this->assertSame('critical', $output['checks']['cash_ledger']['status']);
        $this->assertSame(1, $output['checks']['cash_ledger']['audit']['issue_count']);
        $this->assertContains('cash_ledger_audit_issues_detected', array_column($output['items'], 'code'));
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
