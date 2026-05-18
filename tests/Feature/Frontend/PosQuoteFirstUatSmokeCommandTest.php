<?php

namespace Tests\Feature\Frontend;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class PosQuoteFirstUatSmokeCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_command_blocks_when_frontend_uat_readiness_is_not_ready(): void
    {
        $this->seed([
            \Database\Seeders\TenantSeeder::class,
            \Database\Seeders\RbacCatalogSeeder::class,
        ]);

        $exitCode = Artisan::call('frontend:pos-quote-first-uat-smoke', [
            '--json' => true,
        ]);
        $payload = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

        $this->assertSame(1, $exitCode);
        $this->assertSame('blocked', $payload['status']);
        $this->assertSame('Frontend UAT readiness is not ready.', $payload['reason']);
        $this->assertNotEmpty($payload['items']);
    }

    public function test_command_executes_full_quote_first_uat_smoke_and_writes_evidence(): void
    {
        $this->seed([
            \Database\Seeders\TenantSeeder::class,
            \Database\Seeders\RbacCatalogSeeder::class,
        ]);

        $this->artisan('frontend:seed-pos-smoke', [
            '--json' => true,
        ])->assertExitCode(0);

        $exitCode = Artisan::call('frontend:pos-quote-first-uat-smoke', [
            '--json' => true,
        ]);
        $payload = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

        $this->assertSame(0, $exitCode);
        $this->assertSame('passed', $payload['status']);
        $this->assertSame('pending_visual_review', $payload['signoff']['status']);
        $this->assertSame(4, $payload['snapshots']['delta']['sales_count']);
        $this->assertSame(-4, $payload['snapshots']['delta']['regular_stock']);
        $this->assertSame(-1, $payload['snapshots']['delta']['controlled_stock']);
        $this->assertSame(1, $payload['snapshots']['delta']['receivables_count']);
        $this->assertSame('passed', $payload['scenarios']['card_regular_quote_checkout']['status']);
        $this->assertSame('passed', $payload['scenarios']['cash_regular_cash_ledger']['status']);
        $this->assertSame('passed', $payload['scenarios']['credit_customer_receivable']['status']);
        $this->assertSame('passed', $payload['scenarios']['controlled_product_prescription']['status']);
        $this->assertFileExists($payload['artifacts']['latest_evidence_path']);
        $this->assertFileExists($payload['artifacts']['evidence_path']);

        $this->assertSame(4, DB::table('pricing_quotes')->where('tenant_id', 10)->where('status', 'consumed')->count());
        $this->assertSame(4, DB::table('sales')->where('tenant_id', 10)->count());
        $this->assertSame(1, DB::table('sale_receivables')->where('tenant_id', 10)->count());
    }
}
