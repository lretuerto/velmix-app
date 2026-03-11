<?php

namespace Tests\Feature\Billing;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class BillingCreditNoteFlowTest extends TestCase
{
    use RefreshDatabase;

    public function test_creates_credit_note_for_cash_sale_and_refunds_cash(): void
    {
        [$admin, $saleId, $lotId] = $this->seedCashSaleWithVoucher();

        $this->actingAs($admin)
            ->withHeader('X-Tenant-Id', '10')
            ->postJson('/cash/sessions/open', [
                'opening_amount' => 100,
            ])
            ->assertOk();

        $this->actingAs($admin)
            ->withHeader('X-Tenant-Id', '10')
            ->postJson('/billing/credit-notes', [
                'sale_id' => $saleId,
                'reason' => 'Devolucion total mostrador',
            ])
            ->assertOk()
            ->assertJsonPath('data.status', 'pending')
            ->assertJsonPath('data.refunded_amount', 21)
            ->assertJsonPath('data.refund_payment_method', 'cash');

        $creditNoteId = DB::table('sale_credit_notes')->where('sale_id', $saleId)->value('id');
        $outboxPayload = json_decode((string) DB::table('outbox_events')->where('aggregate_type', 'sale_credit_note')->where('aggregate_id', $creditNoteId)->value('payload'), true, 512, JSON_THROW_ON_ERROR);

        $this->assertDatabaseHas('sales', [
            'id' => $saleId,
            'status' => 'credited',
        ]);

        $this->assertDatabaseHas('lots', [
            'id' => $lotId,
            'stock_quantity' => 60,
        ]);

        $this->assertDatabaseHas('stock_movements', [
            'sale_id' => $saleId,
            'type' => 'credit_note_reversal',
            'quantity' => 6,
        ]);

        $this->assertDatabaseHas('sale_refunds', [
            'sale_credit_note_id' => $creditNoteId,
            'payment_method' => 'cash',
            'amount' => 21.00,
        ]);

        $this->assertDatabaseHas('billing_document_payloads', [
            'tenant_id' => 10,
            'aggregate_type' => 'sale_credit_note',
            'aggregate_id' => $creditNoteId,
            'provider_code' => 'fake_sunat',
            'provider_environment' => 'sandbox',
            'schema_version' => 'fake_sunat.v1',
            'document_kind' => 'credit_note',
            'document_number' => 'NC01-1',
        ]);
        $this->assertSame('fake_sunat.v1', $outboxPayload['schema_version']);
        $this->assertSame('credit_note', $outboxPayload['document_kind']);
        $this->assertArrayHasKey('billing_payload_id', $outboxPayload);
        $this->assertArrayHasKey('document_payload', $outboxPayload);

        $this->assertDatabaseHas('cash_movements', [
            'type' => 'credit_note_refund',
            'amount' => 21.00,
        ]);

        $this->actingAs($admin)
            ->withHeader('X-Tenant-Id', '10')
            ->getJson('/cash/sessions/current')
            ->assertOk()
            ->assertJsonPath('data.refund_out_total', 21)
            ->assertJsonPath('data.expected_amount', 79);

        $this->actingAs($admin)
            ->withHeader('X-Tenant-Id', '10')
            ->getJson("/billing/credit-notes/{$creditNoteId}")
            ->assertOk()
            ->assertJsonPath('data.refund.amount', 21)
            ->assertJsonPath('data.voucher.series', 'B001');

        $this->actingAs($admin)
            ->withHeader('X-Tenant-Id', '10')
            ->getJson("/billing/credit-notes/{$creditNoteId}/payloads")
            ->assertOk()
            ->assertJsonPath('data.0.aggregate_type', 'sale_credit_note')
            ->assertJsonPath('data.0.provider_code', 'fake_sunat')
            ->assertJsonPath('data.0.schema_version', 'fake_sunat.v1')
            ->assertJsonPath('data.0.document_kind', 'credit_note')
            ->assertJsonPath('data.0.document_number', 'NC01-1');
    }

    public function test_can_create_partial_credit_note_and_keep_sale_completed_until_fully_credited(): void
    {
        [$admin, $saleId, $lotId] = $this->seedCashSaleWithVoucher();
        $saleItemId = DB::table('sale_items')->where('sale_id', $saleId)->value('id');

        $this->actingAs($admin)
            ->withHeader('X-Tenant-Id', '10')
            ->postJson('/cash/sessions/open', [
                'opening_amount' => 100,
            ])
            ->assertOk();

        $this->actingAs($admin)
            ->withHeader('X-Tenant-Id', '10')
            ->postJson('/billing/credit-notes', [
                'sale_id' => $saleId,
                'reason' => 'Devolucion parcial',
                'items' => [[
                    'sale_item_id' => $saleItemId,
                    'quantity' => 2,
                ]],
            ])
            ->assertOk()
            ->assertJsonPath('data.total_amount', 7)
            ->assertJsonPath('data.refunded_amount', 7)
            ->assertJsonPath('data.items.0.quantity', 2);

        $creditNoteId = DB::table('sale_credit_notes')->where('sale_id', $saleId)->max('id');

        $this->assertDatabaseHas('sales', [
            'id' => $saleId,
            'status' => 'completed',
        ]);

        $this->assertDatabaseHas('lots', [
            'id' => $lotId,
            'stock_quantity' => 56,
        ]);

        $this->assertDatabaseHas('sale_credit_note_items', [
            'sale_credit_note_id' => $creditNoteId,
            'sale_item_id' => $saleItemId,
            'quantity' => 2,
            'line_total' => 7.00,
        ]);

        $this->actingAs($admin)
            ->withHeader('X-Tenant-Id', '10')
            ->getJson("/pos/sales/{$saleId}")
            ->assertOk()
            ->assertJsonPath('data.status', 'completed')
            ->assertJsonPath('data.credit_summary.count', 1)
            ->assertJsonPath('data.credit_summary.credited_total', 7)
            ->assertJsonPath('data.items.0.credited_quantity', 2)
            ->assertJsonPath('data.items.0.remaining_quantity', 4);
    }

    public function test_can_apply_second_credit_note_for_remaining_quantity(): void
    {
        [$admin, $saleId] = $this->seedCashSaleWithVoucher();
        $saleItemId = DB::table('sale_items')->where('sale_id', $saleId)->value('id');

        $this->actingAs($admin)
            ->withHeader('X-Tenant-Id', '10')
            ->postJson('/cash/sessions/open', [
                'opening_amount' => 100,
            ])
            ->assertOk();

        $this->actingAs($admin)
            ->withHeader('X-Tenant-Id', '10')
            ->postJson('/billing/credit-notes', [
                'sale_id' => $saleId,
                'reason' => 'Primera devolucion',
                'items' => [[
                    'sale_item_id' => $saleItemId,
                    'quantity' => 2,
                ]],
            ])
            ->assertOk();

        $this->actingAs($admin)
            ->withHeader('X-Tenant-Id', '10')
            ->postJson('/billing/credit-notes', [
                'sale_id' => $saleId,
                'reason' => 'Saldo de devolucion',
                'items' => [[
                    'sale_item_id' => $saleItemId,
                    'quantity' => 4,
                ]],
            ])
            ->assertOk()
            ->assertJsonPath('data.number', 2)
            ->assertJsonPath('data.total_amount', 14)
            ->assertJsonPath('data.refunded_amount', 14);

        $this->assertDatabaseHas('sales', [
            'id' => $saleId,
            'status' => 'credited',
        ]);

        $this->assertSame(2, DB::table('sale_credit_notes')->where('sale_id', $saleId)->count());
        $this->assertSame(21.0, (float) DB::table('sale_refunds')->where('sale_id', $saleId)->sum('amount'));
    }

    public function test_creates_credit_note_for_unpaid_credit_sale_without_refund(): void
    {
        [$admin, $saleId, $receivableId] = $this->seedCreditSaleWithVoucher(false);

        $this->actingAs($admin)
            ->withHeader('X-Tenant-Id', '10')
            ->postJson('/billing/credit-notes', [
                'sale_id' => $saleId,
                'reason' => 'Venta a credito sin cobranza',
            ])
            ->assertOk()
            ->assertJsonPath('data.refunded_amount', 0)
            ->assertJsonPath('data.refund_payment_method', null);

        $this->assertDatabaseHas('sale_receivables', [
            'id' => $receivableId,
            'status' => 'credited',
            'outstanding_amount' => 0,
        ]);
    }

    public function test_partial_credit_note_reduces_unpaid_receivable_balance(): void
    {
        [$admin, $saleId, $receivableId] = $this->seedCreditSaleWithVoucher(false);
        $saleItemId = DB::table('sale_items')->where('sale_id', $saleId)->value('id');

        $this->actingAs($admin)
            ->withHeader('X-Tenant-Id', '10')
            ->postJson('/billing/credit-notes', [
                'sale_id' => $saleId,
                'reason' => 'Devolucion parcial credito',
                'items' => [[
                    'sale_item_id' => $saleItemId,
                    'quantity' => 2,
                ]],
            ])
            ->assertOk()
            ->assertJsonPath('data.total_amount', 7)
            ->assertJsonPath('data.refunded_amount', 0);

        $this->assertDatabaseHas('sale_receivables', [
            'id' => $receivableId,
            'total_amount' => 7.00,
            'paid_amount' => 0.00,
            'outstanding_amount' => 7.00,
            'status' => 'pending',
        ]);

        $this->assertDatabaseHas('sales', [
            'id' => $saleId,
            'status' => 'completed',
        ]);
    }

    public function test_rejects_credit_note_when_sale_has_mixed_receivable_payments(): void
    {
        [$admin, $saleId] = $this->seedCreditSaleWithVoucher(true);

        $this->actingAs($admin)
            ->withHeader('X-Tenant-Id', '10')
            ->postJson('/billing/credit-notes', [
                'sale_id' => $saleId,
                'reason' => 'Intento invalido',
            ])
            ->assertStatus(422);
    }

    public function test_rejects_credit_note_when_same_sale_item_is_repeated_and_exceeds_remaining_quantity(): void
    {
        [$admin, $saleId, $lotId] = $this->seedCashSaleWithVoucher();
        $saleItemId = DB::table('sale_items')->where('sale_id', $saleId)->value('id');

        $this->actingAs($admin)
            ->withHeader('X-Tenant-Id', '10')
            ->postJson('/cash/sessions/open', [
                'opening_amount' => 100,
            ])
            ->assertOk();

        $this->actingAs($admin)
            ->withHeader('X-Tenant-Id', '10')
            ->postJson('/billing/credit-notes', [
                'sale_id' => $saleId,
                'reason' => 'Payload duplicado',
                'items' => [
                    [
                        'sale_item_id' => $saleItemId,
                        'quantity' => 4,
                    ],
                    [
                        'sale_item_id' => $saleItemId,
                        'quantity' => 3,
                    ],
                ],
            ])
            ->assertStatus(422);

        $this->assertSame(0, DB::table('sale_credit_notes')->where('sale_id', $saleId)->count());
        $this->assertDatabaseHas('lots', [
            'id' => $lotId,
            'stock_quantity' => 54,
        ]);
    }

    private function seedCashSaleWithVoucher(): array
    {
        $this->seed([
            \Database\Seeders\TenantSeeder::class,
            \Database\Seeders\RbacCatalogSeeder::class,
            \Database\Seeders\InventoryCatalogSeeder::class,
        ]);

        $admin = $this->seedUserWithRole(10, 'ADMIN');
        $lotId = DB::table('lots')->where('tenant_id', 10)->where('code', 'L-PARA-001')->value('id');
        $productId = DB::table('lots')->where('id', $lotId)->value('product_id');

        $saleId = DB::table('sales')->insertGetId([
            'tenant_id' => 10,
            'user_id' => $admin->id,
            'reference' => 'SALE-CN-CASH',
            'status' => 'completed',
            'payment_method' => 'cash',
            'total_amount' => 21.00,
            'gross_cost' => 8.40,
            'gross_margin' => 12.60,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('sale_items')->insert([
            'sale_id' => $saleId,
            'lot_id' => $lotId,
            'product_id' => $productId,
            'quantity' => 6,
            'unit_price' => 3.50,
            'unit_cost_snapshot' => 1.40,
            'line_total' => 21.00,
            'cost_amount' => 8.40,
            'gross_margin' => 12.60,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('lots')->where('id', $lotId)->update([
            'stock_quantity' => 54,
            'updated_at' => now(),
        ]);

        DB::table('electronic_vouchers')->insert([
            'tenant_id' => 10,
            'sale_id' => $saleId,
            'type' => 'boleta',
            'series' => 'B001',
            'number' => 1,
            'status' => 'accepted',
            'sunat_ticket' => 'SUNAT-CN-001',
            'rejection_reason' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return [$admin, $saleId, $lotId];
    }

    private function seedCreditSaleWithVoucher(bool $mixedPayments): array
    {
        $this->seed([
            \Database\Seeders\TenantSeeder::class,
            \Database\Seeders\RbacCatalogSeeder::class,
            \Database\Seeders\InventoryCatalogSeeder::class,
        ]);

        $admin = $this->seedUserWithRole(10, 'ADMIN');
        $customerId = DB::table('customers')->insertGetId([
            'tenant_id' => 10,
            'document_type' => 'dni',
            'document_number' => '99887766',
            'name' => 'Cliente Nota Credito',
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $lotId = DB::table('lots')->where('tenant_id', 10)->where('code', 'L-PARA-001')->value('id');
        $productId = DB::table('lots')->where('id', $lotId)->value('product_id');

        $saleId = DB::table('sales')->insertGetId([
            'tenant_id' => 10,
            'user_id' => $admin->id,
            'customer_id' => $customerId,
            'reference' => 'SALE-CN-CREDIT-'.($mixedPayments ? 'MIX' : 'OPEN'),
            'status' => 'completed',
            'payment_method' => 'credit',
            'total_amount' => 14.00,
            'gross_cost' => 5.60,
            'gross_margin' => 8.40,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('sale_items')->insert([
            'sale_id' => $saleId,
            'lot_id' => $lotId,
            'product_id' => $productId,
            'quantity' => 4,
            'unit_price' => 3.50,
            'unit_cost_snapshot' => 1.40,
            'line_total' => 14.00,
            'cost_amount' => 5.60,
            'gross_margin' => 8.40,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('lots')->where('id', $lotId)->update([
            'stock_quantity' => 56,
            'updated_at' => now(),
        ]);

        $receivableId = DB::table('sale_receivables')->insertGetId([
            'tenant_id' => 10,
            'customer_id' => $customerId,
            'sale_id' => $saleId,
            'total_amount' => 14.00,
            'paid_amount' => $mixedPayments ? 6.00 : 0,
            'outstanding_amount' => $mixedPayments ? 8.00 : 14.00,
            'status' => $mixedPayments ? 'partial_paid' : 'pending',
            'due_at' => now()->addDays(10),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        if ($mixedPayments) {
            DB::table('sale_receivable_payments')->insert([
                [
                    'sale_receivable_id' => $receivableId,
                    'user_id' => $admin->id,
                    'amount' => 3.00,
                    'payment_method' => 'cash',
                    'reference' => 'PAY-CN-001',
                    'paid_at' => now()->subDay(),
                    'created_at' => now(),
                    'updated_at' => now(),
                ],
                [
                    'sale_receivable_id' => $receivableId,
                    'user_id' => $admin->id,
                    'amount' => 3.00,
                    'payment_method' => 'transfer',
                    'reference' => 'PAY-CN-002',
                    'paid_at' => now(),
                    'created_at' => now(),
                    'updated_at' => now(),
                ],
            ]);
        }

        DB::table('electronic_vouchers')->insert([
            'tenant_id' => 10,
            'sale_id' => $saleId,
            'type' => 'boleta',
            'series' => 'B001',
            'number' => $mixedPayments ? 2 : 3,
            'status' => 'accepted',
            'sunat_ticket' => 'SUNAT-CN-CREDIT',
            'rejection_reason' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return [$admin, $saleId, $receivableId];
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
