<?php

namespace Tests\Feature\Billing;

use App\Services\Billing\OutboxDispatchService;
use App\Models\User;
use Exception;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Database\QueryException;
use Tests\TestCase;

class BillingDispatchCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_command_dispatches_pending_events_for_all_tenants(): void
    {
        $this->seed([
            \Database\Seeders\TenantSeeder::class,
            \Database\Seeders\RbacCatalogSeeder::class,
        ]);

        [$voucher10Id, $event10Id] = $this->seedPendingVoucher(10, 1);
        [$voucher20Id, $event20Id] = $this->seedPendingVoucher(20, 1);

        $exitCode = Artisan::call('billing:dispatch-outbox', [
            '--limit' => 5,
        ]);
        $output = Artisan::output();

        $this->assertSame(0, $exitCode);
        $this->assertStringContainsString('"tenant_count": 2', $output);
        $this->assertMatchesRegularExpression('/"total_processed_count"\s*:\s*2/', $output);

        $this->assertDatabaseHas('electronic_vouchers', [
            'id' => $voucher10Id,
            'status' => 'accepted',
        ]);

        $this->assertDatabaseHas('electronic_vouchers', [
            'id' => $voucher20Id,
            'status' => 'accepted',
        ]);

        $this->assertDatabaseHas('outbox_events', [
            'id' => $event10Id,
            'status' => 'processed',
        ]);

        $this->assertDatabaseHas('outbox_events', [
            'id' => $event20Id,
            'status' => 'processed',
        ]);
    }

    public function test_command_reports_when_no_pending_events_exist(): void
    {
        $this->seed(\Database\Seeders\TenantSeeder::class);

        $exitCode = Artisan::call('billing:dispatch-outbox', [
            '--limit' => 5,
        ]);
        $output = Artisan::output();

        $this->assertSame(0, $exitCode);
        $this->assertStringContainsString('No pending outbox events.', trim($output));
    }

    public function test_command_can_exit_gracefully_when_outbox_storage_is_not_ready(): void
    {
        $service = \Mockery::mock(OutboxDispatchService::class);
        $service->shouldReceive('pendingTenantIds')
            ->once()
            ->andThrow(new QueryException('sqlite', 'select * from outbox_events', [], new Exception('storage not ready')));
        $this->app->instance(OutboxDispatchService::class, $service);

        $exitCode = Artisan::call('billing:dispatch-outbox', [
            '--limit' => 5,
            '--graceful-if-unmigrated' => true,
        ]);
        $output = Artisan::output();

        $this->assertSame(0, $exitCode);
        $this->assertStringContainsString('Outbox tables are not ready. Run php artisan migrate first.', trim($output));
    }

    public function test_command_can_exit_gracefully_when_specific_tenant_storage_is_not_ready(): void
    {
        $service = \Mockery::mock(OutboxDispatchService::class);
        $service->shouldNotReceive('pendingTenantIds');
        $service->shouldReceive('dispatchBatch')
            ->once()
            ->with(10, 5, null)
            ->andThrow(new QueryException('sqlite', 'select * from outbox_events where tenant_id = ?', [10], new Exception('storage not ready')));
        $this->app->instance(OutboxDispatchService::class, $service);

        $exitCode = Artisan::call('billing:dispatch-outbox', [
            '--tenant' => 10,
            '--limit' => 5,
            '--graceful-if-unmigrated' => true,
        ]);
        $output = Artisan::output();

        $this->assertSame(0, $exitCode);
        $this->assertStringContainsString('Outbox tables are not ready. Run php artisan migrate first.', trim($output));
    }

    private function seedPendingVoucher(int $tenantId, int $number): array
    {
        $saleId = DB::table('sales')->insertGetId([
            'tenant_id' => $tenantId,
            'user_id' => User::factory()->create()->id,
            'reference' => sprintf('SALE-CMD-%d-%d', $tenantId, $number),
            'status' => 'completed',
            'total_amount' => 50.00,
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

        $eventId = DB::table('outbox_events')->insertGetId([
            'tenant_id' => $tenantId,
            'aggregate_type' => 'electronic_voucher',
            'aggregate_id' => $voucherId,
            'event_type' => 'voucher.created',
            'payload' => json_encode(['voucher_id' => $voucherId], JSON_THROW_ON_ERROR),
            'status' => 'pending',
            'retry_count' => 0,
            'last_error' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return [$voucherId, $eventId];
    }
}
