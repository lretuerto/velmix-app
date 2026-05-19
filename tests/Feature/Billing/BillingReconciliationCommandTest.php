<?php

namespace Tests\Feature\Billing;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class BillingReconciliationCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_command_reconciles_pending_documents_for_tenant(): void
    {
        $this->seed([
            \Database\Seeders\TenantSeeder::class,
            \Database\Seeders\RbacCatalogSeeder::class,
        ]);

        [$voucherId, $eventId] = $this->seedPendingVoucherWithPayload(10, 1);

        $exitCode = Artisan::call('billing:reconcile-pending', [
            '--tenant' => 10,
            '--limit' => 5,
            '--simulate-result' => 'accepted',
        ]);

        $this->assertSame(0, $exitCode);
        $this->assertStringContainsString('"processed_count": 1', Artisan::output());

        $this->assertDatabaseHas('electronic_vouchers', [
            'id' => $voucherId,
            'status' => 'accepted',
        ]);

        $this->assertDatabaseHas('outbox_events', [
            'id' => $eventId,
            'status' => 'processed',
        ]);
    }

    private function seedPendingVoucherWithPayload(int $tenantId, int $number): array
    {
        $saleId = DB::table('sales')->insertGetId([
            'tenant_id' => $tenantId,
            'user_id' => User::factory()->create()->id,
            'reference' => sprintf('SALE-RECON-CMD-%d-%d', $tenantId, $number),
            'status' => 'completed',
            'payment_method' => 'cash',
            'total_amount' => 75,
            'gross_cost' => 30,
            'gross_margin' => 45,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $voucherId = DB::table('electronic_vouchers')->insertGetId([
            'tenant_id' => $tenantId,
            'sale_id' => $saleId,
            'type' => 'boleta',
            'series' => 'B001',
            'number' => $number,
            'status' => 'pending',
            'sunat_ticket' => null,
            'rejection_reason' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('billing_document_payloads')->insert([
            'tenant_id' => $tenantId,
            'aggregate_type' => 'electronic_voucher',
            'aggregate_id' => $voucherId,
            'provider_code' => 'fake_sunat',
            'provider_environment' => 'sandbox',
            'schema_version' => 'fake_sunat.v1',
            'document_kind' => 'voucher',
            'document_number' => 'B001-1',
            'payload_hash' => hash('sha256', '{}'),
            'payload' => json_encode([
                'document' => [
                    'id' => $voucherId,
                    'series' => 'B001',
                    'number' => $number,
                ],
            ], JSON_THROW_ON_ERROR),
            'created_by_user_id' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $eventId = DB::table('outbox_events')->insertGetId([
            'tenant_id' => $tenantId,
            'aggregate_type' => 'electronic_voucher',
            'aggregate_id' => $voucherId,
            'event_type' => 'voucher.created',
            'payload' => json_encode([
                'voucher_id' => $voucherId,
            ], JSON_THROW_ON_ERROR),
            'status' => 'pending',
            'retry_count' => 0,
            'last_error' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return [$voucherId, $eventId];
    }
}
