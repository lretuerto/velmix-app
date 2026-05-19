<?php

namespace Tests\Feature\Reports;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

class PlatformObservabilityReportFlowTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_can_read_platform_observability_report(): void
    {
        $this->seed([
            \Database\Seeders\TenantSeeder::class,
            \Database\Seeders\RbacCatalogSeeder::class,
        ]);

        $admin = $this->seedUserWithRole(10, 'ADMIN');
        $root = storage_path('framework/testing/platform-observability-recovery');
        File::deleteDirectory($root);
        File::ensureDirectoryExists($root.'/backups/history');
        File::ensureDirectoryExists($root.'/restore-drills');
        File::ensureDirectoryExists($root.'/staging-certifications/history');
        File::ensureDirectoryExists($root.'/release-promotions/history');
        File::ensureDirectoryExists($root.'/release-cutovers/history');
        File::ensureDirectoryExists($root.'/operational-certifications/history');

        config([
            'app.env' => 'staging',
            'app.debug' => false,
            'queue.default' => 'database',
            'logging.default' => 'stack',
            'logging.channels.stack.channels' => ['single', 'stderr_json'],
            'velmix.alerts.notifications.channels' => ['log', 'slack'],
            'velmix.alerts.notifications.minimum_severity' => 'warning',
            'velmix.alerts.notifications.slack_webhook_url' => 'https://hooks.slack.example.test/services/velmix',
            'velmix.alerts.notifications.slack_channel' => '#ops-alerts',
            'velmix.backup.enabled' => true,
            'velmix.backup.storage_path' => $root.'/backups',
            'velmix.backup.history_path' => $root.'/backups/history',
            'velmix.backup.restore_drill_path' => $root.'/restore-drills',
            'velmix.backup.encryption_passphrase' => 'test-passphrase',
            'velmix.staging_certification.expected_environment' => 'staging',
            'velmix.staging_certification.required_environments' => ['staging'],
            'velmix.staging_certification.storage_path' => $root.'/staging-certifications',
            'velmix.staging_certification.history_path' => $root.'/staging-certifications/history',
            'velmix.staging_certification.release_identifier' => 'release-2026-04-21-001',
            'velmix.release_promotion.expected_environment' => 'staging',
            'velmix.release_promotion.required_environments' => ['staging'],
            'velmix.release_promotion.storage_path' => $root.'/release-promotions',
            'velmix.release_promotion.history_path' => $root.'/release-promotions/history',
            'velmix.release_promotion.release_identifier' => 'release-2026-04-21-001',
            'velmix.release_cutover.expected_environment' => 'staging',
            'velmix.release_cutover.required_environments' => ['staging'],
            'velmix.release_cutover.storage_path' => $root.'/release-cutovers',
            'velmix.release_cutover.history_path' => $root.'/release-cutovers/history',
            'velmix.release_cutover.release_identifier' => 'release-2026-04-21-001',
            'velmix.operational_certification.expected_environment' => 'staging',
            'velmix.operational_certification.required_environments' => ['staging'],
            'velmix.operational_certification.storage_path' => $root.'/operational-certifications',
            'velmix.operational_certification.history_path' => $root.'/operational-certifications/history',
            'velmix.operational_certification.release_identifier' => 'release-2026-04-21-001',
        ]);

        try {
            app(\App\Services\Platform\BackupRecoveryService::class)->recordBackup(
                's3://velmix-prod/backups/latest.sql.gz',
                'sha256:test',
                2048,
                'managed-snapshot',
                now()->subHour()->toIso8601String(),
            );
            app(\App\Services\Platform\BackupRecoveryService::class)->restoreDrillSummary();
            app(\App\Services\Platform\StagingCertificationService::class)->recordCertification(
                'release-2026-04-21-001',
                'https://staging.example.test/evidence/deploy',
                'https://staging.example.test/evidence/rollback',
                'https://staging.example.test/evidence/smoke',
                null,
                'release-bot',
            );
            app(\App\Services\Platform\ReleasePromotionService::class)->recordApproval(
                'release-2026-04-21-001',
                'https://staging.example.test/evidence/approve',
                'https://staging.example.test/evidence/rollback',
                'release-bot',
            );
            app(\App\Services\Platform\ReleaseCutoverService::class)->recordDecision(
                'release-2026-04-21-001',
                'https://staging.example.test/evidence/cutover',
                'https://staging.example.test/evidence/rollback',
                'https://staging.example.test/evidence/monitoring',
                'release-bot',
            );
            app(\App\Services\Platform\OperationalCertificationService::class)->recordCertification(
                'release-2026-04-21-001',
                'https://staging.example.test/evidence/deploy',
                'https://staging.example.test/evidence/rollback',
                's3://velmix-prod/backups/latest.sql.gz',
                'https://staging.example.test/evidence/restore',
                'https://staging.example.test/evidence/monitoring',
                'release-bot',
            );

            DB::table('outbox_events')->insert([
                'tenant_id' => 10,
                'aggregate_type' => 'electronic_voucher',
                'aggregate_id' => 900,
                'event_type' => 'voucher_issued',
                'payload' => json_encode(['document_number' => 'B001-000900'], JSON_THROW_ON_ERROR),
                'status' => 'failed',
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            $response = $this->actingAs($admin)
                ->withHeader('X-Tenant-Id', '10')
                ->getJson('/reports/platform-observability?date=2026-04-17');

            $response->assertOk()
                ->assertJsonPath('data.tenant_id', 10)
                ->assertJsonPath('data.request_correlation.request_id_header', 'X-Request-Id')
                ->assertJsonPath('data.notifications.slack_enabled', true)
                ->assertJsonPath('data.delivery.minimum_severity', 'warning')
                ->assertJsonPath('data.recovery.backup.status', 'ok')
                ->assertJsonPath('data.recovery.restore_drill.status', 'ok')
                ->assertJsonPath('data.certification.staging.status', 'ok')
                ->assertJsonPath('data.certification.staging.latest_certification.release', 'release-2026-04-21-001')
                ->assertJsonPath('data.promotion.latest_approval.release', 'release-2026-04-21-001')
                ->assertJsonPath('data.cutover.latest_decision.release', 'release-2026-04-21-001')
                ->assertJsonPath('data.operational_certification.latest_certificate.release', 'release-2026-04-21-001')
                ->assertJsonPath('data.operational_certification.certificate_recorded', true);

            $data = $response->json('data');
            $channels = collect($data['delivery']['channels']);
            $logChannel = $channels->firstWhere('channel', 'log');
            $slackChannel = $channels->firstWhere('channel', 'slack');

            $this->assertSame('critical', $data['alerts']['status']);
            $this->assertSame('critical', $data['promotion']['status']);
            $this->assertSame('critical', $data['cutover']['status']);
            $this->assertSame('critical', $data['operational_certification']['status']);
            $this->assertGreaterThanOrEqual(1, $data['delivery']['candidate_alert_count']);
            $this->assertSame('ready', is_array($logChannel) ? ($logChannel['status'] ?? null) : null);
            $this->assertSame('ready', is_array($slackChannel) ? ($slackChannel['status'] ?? null) : null);
            $this->assertIsArray($data['recommendations']);
        } finally {
            File::deleteDirectory($root);
        }
    }

    public function test_cashier_cannot_read_platform_observability_report(): void
    {
        $this->seed([
            \Database\Seeders\TenantSeeder::class,
            \Database\Seeders\RbacCatalogSeeder::class,
        ]);

        $cashier = $this->seedUserWithRole(10, 'CAJERO');

        $this->actingAs($cashier)
            ->withHeader('X-Tenant-Id', '10')
            ->getJson('/reports/platform-observability')
            ->assertStatus(403);
    }

    private function seedUserWithRole(int $tenantId, string $roleCode): User
    {
        $user = User::factory()->create();
        $roleId = DB::table('roles')->where('code', $roleCode)->value('id');

        DB::table('tenant_user')->insert([
            'tenant_id' => $tenantId,
            'user_id' => $user->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('tenant_user_role')->insert([
            'tenant_id' => $tenantId,
            'user_id' => $user->id,
            'role_id' => $roleId,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $user;
    }
}
