<?php

namespace Tests\Feature\Reports;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class DailyReportFlowTest extends TestCase
{
    use RefreshDatabase;

    public function test_reads_daily_operational_summary_for_current_tenant(): void
    {
        $this->seedBaseCatalog();
        $admin = $this->seedUserWithRole(10, 'ADMIN');
        $saleUserId = User::factory()->create()->id;

        DB::table('sales')->insert([
            [
                'tenant_id' => 10,
                'user_id' => $saleUserId,
                'cancelled_by_user_id' => null,
                'reference' => 'SALE-REPORT-CARD',
                'status' => 'completed',
                'payment_method' => 'card',
                'cancel_reason' => null,
                'cancelled_at' => null,
                'total_amount' => 20.00,
                'gross_cost' => 9.00,
                'gross_margin' => 11.00,
                'created_at' => '2026-03-11 13:00:00',
                'updated_at' => '2026-03-11 13:00:00',
            ],
            [
                'tenant_id' => 10,
                'user_id' => $saleUserId,
                'cancelled_by_user_id' => null,
                'reference' => 'SALE-REPORT-OK',
                'status' => 'completed',
                'payment_method' => 'cash',
                'cancel_reason' => null,
                'cancelled_at' => null,
                'total_amount' => 40.50,
                'gross_cost' => 20.00,
                'gross_margin' => 20.50,
                'created_at' => '2026-03-11 09:00:00',
                'updated_at' => '2026-03-11 09:00:00',
            ],
            [
                'tenant_id' => 10,
                'user_id' => $saleUserId,
                'cancelled_by_user_id' => $admin->id,
                'reference' => 'SALE-REPORT-CANCEL',
                'status' => 'cancelled',
                'payment_method' => 'cash',
                'cancel_reason' => 'Cliente desistio',
                'cancelled_at' => '2026-03-11 12:30:00',
                'total_amount' => 15.00,
                'gross_cost' => 7.00,
                'gross_margin' => 8.00,
                'created_at' => '2026-03-11 10:00:00',
                'updated_at' => '2026-03-11 12:30:00',
            ],
            [
                'tenant_id' => 10,
                'user_id' => $saleUserId,
                'cancelled_by_user_id' => null,
                'reference' => 'SALE-REPORT-OLD',
                'status' => 'completed',
                'payment_method' => 'card',
                'cancel_reason' => null,
                'cancelled_at' => null,
                'total_amount' => 99.00,
                'gross_cost' => 55.00,
                'gross_margin' => 44.00,
                'created_at' => '2026-03-10 08:00:00',
                'updated_at' => '2026-03-10 08:00:00',
            ],
        ]);

        $cardSaleId = DB::table('sales')->where('reference', 'SALE-REPORT-CARD')->value('id');
        $completedSaleId = DB::table('sales')->where('reference', 'SALE-REPORT-OK')->value('id');
        $cancelledSaleId = DB::table('sales')->where('reference', 'SALE-REPORT-CANCEL')->value('id');
        $productId = DB::table('products')->insertGetId([
            'tenant_id' => 10,
            'sku' => 'DASH-001',
            'name' => 'Producto Dashboard',
            'status' => 'active',
            'is_controlled' => false,
            'last_cost' => 5.00,
            'average_cost' => 5.00,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $lotId = DB::table('lots')->insertGetId([
            'tenant_id' => 10,
            'product_id' => $productId,
            'code' => 'L-DASH-001',
            'expires_at' => '2028-12-31',
            'stock_quantity' => 100,
            'status' => 'available',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('sale_items')->insert([
            [
                'sale_id' => $cardSaleId,
                'lot_id' => $lotId,
                'product_id' => $productId,
                'quantity' => 2,
                'unit_price' => 10.00,
                'unit_cost_snapshot' => 4.50,
                'line_total' => 20.00,
                'cost_amount' => 9.00,
                'gross_margin' => 11.00,
                'prescription_code' => null,
                'approval_code' => null,
                'created_at' => '2026-03-11 13:00:00',
                'updated_at' => '2026-03-11 13:00:00',
            ],
            [
                'sale_id' => $completedSaleId,
                'lot_id' => $lotId,
                'product_id' => $productId,
                'quantity' => 3,
                'unit_price' => 13.50,
                'unit_cost_snapshot' => 6.67,
                'line_total' => 40.50,
                'cost_amount' => 20.00,
                'gross_margin' => 20.50,
                'prescription_code' => null,
                'approval_code' => null,
                'created_at' => '2026-03-11 09:00:00',
                'updated_at' => '2026-03-11 09:00:00',
            ],
            [
                'sale_id' => $cancelledSaleId,
                'lot_id' => $lotId,
                'product_id' => $productId,
                'quantity' => 1,
                'unit_price' => 15.00,
                'unit_cost_snapshot' => 7.00,
                'line_total' => 15.00,
                'cost_amount' => 7.00,
                'gross_margin' => 8.00,
                'prescription_code' => null,
                'approval_code' => null,
                'created_at' => '2026-03-11 10:00:00',
                'updated_at' => '2026-03-11 10:00:00',
            ],
        ]);

        DB::table('electronic_vouchers')->insert([
            [
                'tenant_id' => 10,
                'sale_id' => $completedSaleId,
                'type' => 'boleta',
                'series' => 'B001',
                'number' => 1,
                'status' => 'accepted',
                'sunat_ticket' => 'SUNAT-101010',
                'rejection_reason' => null,
                'created_at' => '2026-03-11 09:05:00',
                'updated_at' => '2026-03-11 09:06:00',
            ],
            [
                'tenant_id' => 10,
                'sale_id' => $cancelledSaleId,
                'type' => 'boleta',
                'series' => 'B001',
                'number' => 2,
                'status' => 'rejected',
                'sunat_ticket' => null,
                'rejection_reason' => 'Rejected by SUNAT validation.',
                'created_at' => '2026-03-11 11:00:00',
                'updated_at' => '2026-03-11 11:01:00',
            ],
        ]);

        $foreignSaleId = DB::table('sales')->insertGetId([
            'tenant_id' => 20,
            'user_id' => User::factory()->create()->id,
            'cancelled_by_user_id' => null,
            'reference' => 'SALE-OTHER-TENANT',
            'status' => 'completed',
            'payment_method' => 'transfer',
            'cancel_reason' => null,
            'cancelled_at' => null,
            'total_amount' => 999.00,
            'gross_cost' => 400.00,
            'gross_margin' => 599.00,
            'created_at' => '2026-03-11 09:15:00',
            'updated_at' => '2026-03-11 09:15:00',
        ]);

        DB::table('electronic_vouchers')->insert([
            'tenant_id' => 20,
            'sale_id' => $foreignSaleId,
            'type' => 'boleta',
            'series' => 'B001',
            'number' => 1,
            'status' => 'failed',
            'sunat_ticket' => null,
            'rejection_reason' => null,
            'created_at' => '2026-03-11 09:20:00',
            'updated_at' => '2026-03-11 09:21:00',
        ]);

        DB::table('cash_sessions')->insert([
            [
                'tenant_id' => 10,
                'opened_by_user_id' => $admin->id,
                'closed_by_user_id' => $admin->id,
                'opening_amount' => 100.00,
                'expected_amount' => 140.50,
                'counted_amount' => 141.00,
                'discrepancy_amount' => 0.50,
                'status' => 'closed',
                'opened_at' => '2026-03-11 08:00:00',
                'closed_at' => '2026-03-11 18:00:00',
                'created_at' => '2026-03-11 08:00:00',
                'updated_at' => '2026-03-11 18:00:00',
            ],
            [
                'tenant_id' => 10,
                'opened_by_user_id' => $admin->id,
                'closed_by_user_id' => null,
                'opening_amount' => 80.00,
                'expected_amount' => 80.00,
                'counted_amount' => null,
                'discrepancy_amount' => null,
                'status' => 'open',
                'opened_at' => '2026-03-11 19:00:00',
                'closed_at' => null,
                'created_at' => '2026-03-11 19:00:00',
                'updated_at' => '2026-03-11 19:00:00',
            ],
            [
                'tenant_id' => 20,
                'opened_by_user_id' => User::factory()->create()->id,
                'closed_by_user_id' => null,
                'opening_amount' => 500.00,
                'expected_amount' => 500.00,
                'counted_amount' => null,
                'discrepancy_amount' => null,
                'status' => 'open',
                'opened_at' => '2026-03-11 07:00:00',
                'closed_at' => null,
                'created_at' => '2026-03-11 07:00:00',
                'updated_at' => '2026-03-11 07:00:00',
            ],
        ]);

        $tenant10SessionId = DB::table('cash_sessions')
            ->where('tenant_id', 10)
            ->where('opened_at', '2026-03-11 08:00:00')
            ->value('id');

        $tenant20SessionId = DB::table('cash_sessions')
            ->where('tenant_id', 20)
            ->value('id');

        DB::table('cash_movements')->insert([
            [
                'tenant_id' => 10,
                'cash_session_id' => $tenant10SessionId,
                'created_by_user_id' => $admin->id,
                'type' => 'manual_in',
                'amount' => 12.00,
                'reference' => 'ING-REPORT-001',
                'notes' => 'Refuerzo de caja',
                'created_at' => '2026-03-11 09:30:00',
                'updated_at' => '2026-03-11 09:30:00',
            ],
            [
                'tenant_id' => 10,
                'cash_session_id' => $tenant10SessionId,
                'created_by_user_id' => $admin->id,
                'type' => 'manual_out',
                'amount' => 4.50,
                'reference' => 'EGR-REPORT-001',
                'notes' => 'Compra menor',
                'created_at' => '2026-03-11 10:30:00',
                'updated_at' => '2026-03-11 10:30:00',
            ],
            [
                'tenant_id' => 10,
                'cash_session_id' => $tenant10SessionId,
                'created_by_user_id' => $admin->id,
                'type' => 'receivable_in',
                'amount' => 9.00,
                'reference' => 'COBRO-REPORT-001',
                'notes' => 'Cobranza cliente',
                'created_at' => '2026-03-11 14:00:00',
                'updated_at' => '2026-03-11 14:00:00',
            ],
            [
                'tenant_id' => 20,
                'cash_session_id' => $tenant20SessionId,
                'created_by_user_id' => User::factory()->create()->id,
                'type' => 'manual_in',
                'amount' => 99.00,
                'reference' => 'ING-OTHER-TENANT',
                'notes' => null,
                'created_at' => '2026-03-11 09:45:00',
                'updated_at' => '2026-03-11 09:45:00',
            ],
        ]);

        $customerId = DB::table('customers')->insertGetId([
            'tenant_id' => 10,
            'document_type' => 'dni',
            'document_number' => '55667788',
            'name' => 'Cliente Daily',
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $creditSaleId = DB::table('sales')->insertGetId([
            'tenant_id' => 10,
            'user_id' => $saleUserId,
            'customer_id' => $customerId,
            'cancelled_by_user_id' => null,
            'reference' => 'SALE-REPORT-CREDIT',
            'status' => 'completed',
            'payment_method' => 'credit',
            'cancel_reason' => null,
            'cancelled_at' => null,
            'total_amount' => 9.00,
            'gross_cost' => 4.00,
            'gross_margin' => 5.00,
            'created_at' => '2026-03-11 13:50:00',
            'updated_at' => '2026-03-11 13:50:00',
        ]);

        $receivableId = DB::table('sale_receivables')->insertGetId([
            'tenant_id' => 10,
            'customer_id' => $customerId,
            'sale_id' => $creditSaleId,
            'total_amount' => 9.00,
            'paid_amount' => 9.00,
            'outstanding_amount' => 0.00,
            'status' => 'paid',
            'due_at' => '2026-03-20 00:00:00',
            'created_at' => '2026-03-11 13:50:00',
            'updated_at' => '2026-03-11 14:00:00',
        ]);

        DB::table('sale_receivable_payments')->insert([
            'sale_receivable_id' => $receivableId,
            'user_id' => $admin->id,
            'amount' => 9.00,
            'payment_method' => 'cash',
            'reference' => 'COBRO-REPORT-001',
            'paid_at' => '2026-03-11 14:00:00',
            'created_at' => '2026-03-11 14:00:00',
            'updated_at' => '2026-03-11 14:00:00',
        ]);

        $dueTodaySaleId = DB::table('sales')->insertGetId([
            'tenant_id' => 10,
            'user_id' => $saleUserId,
            'customer_id' => $customerId,
            'cancelled_by_user_id' => null,
            'reference' => 'SALE-REPORT-DUE-TODAY',
            'status' => 'completed',
            'payment_method' => 'credit',
            'cancel_reason' => null,
            'cancelled_at' => null,
            'total_amount' => 11.00,
            'gross_cost' => 4.00,
            'gross_margin' => 7.00,
            'created_at' => '2026-03-08 08:30:00',
            'updated_at' => '2026-03-08 08:30:00',
        ]);

        $overdueSaleId = DB::table('sales')->insertGetId([
            'tenant_id' => 10,
            'user_id' => $saleUserId,
            'customer_id' => $customerId,
            'cancelled_by_user_id' => null,
            'reference' => 'SALE-REPORT-DUE-OVERDUE',
            'status' => 'completed',
            'payment_method' => 'credit',
            'cancel_reason' => null,
            'cancelled_at' => null,
            'total_amount' => 13.00,
            'gross_cost' => 5.00,
            'gross_margin' => 8.00,
            'created_at' => '2026-03-10 08:30:00',
            'updated_at' => '2026-03-10 08:30:00',
        ]);

        $upcomingSaleId = DB::table('sales')->insertGetId([
            'tenant_id' => 10,
            'user_id' => $saleUserId,
            'customer_id' => $customerId,
            'cancelled_by_user_id' => null,
            'reference' => 'SALE-REPORT-DUE-UPCOMING',
            'status' => 'completed',
            'payment_method' => 'credit',
            'cancel_reason' => null,
            'cancelled_at' => null,
            'total_amount' => 7.00,
            'gross_cost' => 3.00,
            'gross_margin' => 4.00,
            'created_at' => '2026-03-08 15:00:00',
            'updated_at' => '2026-03-08 15:00:00',
        ]);

        DB::table('sale_receivables')->insert([
            [
                'tenant_id' => 10,
                'customer_id' => $customerId,
                'sale_id' => $dueTodaySaleId,
                'total_amount' => 11.00,
                'paid_amount' => 0.00,
                'outstanding_amount' => 11.00,
                'status' => 'pending',
                'due_at' => '2026-03-11 00:00:00',
                'created_at' => '2026-03-11 08:30:00',
                'updated_at' => '2026-03-11 08:30:00',
            ],
            [
                'tenant_id' => 10,
                'customer_id' => $customerId,
                'sale_id' => $overdueSaleId,
                'total_amount' => 13.00,
                'paid_amount' => 0.00,
                'outstanding_amount' => 13.00,
                'status' => 'pending',
                'due_at' => '2026-03-10 00:00:00',
                'created_at' => '2026-03-10 08:30:00',
                'updated_at' => '2026-03-10 08:30:00',
            ],
            [
                'tenant_id' => 10,
                'customer_id' => $customerId,
                'sale_id' => $upcomingSaleId,
                'total_amount' => 7.00,
                'paid_amount' => 0.00,
                'outstanding_amount' => 7.00,
                'status' => 'pending',
                'due_at' => '2026-03-14 00:00:00',
                'created_at' => '2026-03-11 15:00:00',
                'updated_at' => '2026-03-11 15:00:00',
            ],
        ]);

        $dueTodayReceivableId = DB::table('sale_receivables')->where('sale_id', $dueTodaySaleId)->value('id');
        $overdueReceivableId = DB::table('sale_receivables')->where('sale_id', $overdueSaleId)->value('id');
        $upcomingReceivableId = DB::table('sale_receivables')->where('sale_id', $upcomingSaleId)->value('id');

        DB::table('sale_receivable_follow_ups')->insert([
            [
                'tenant_id' => 10,
                'sale_receivable_id' => $overdueReceivableId,
                'user_id' => $admin->id,
                'type' => 'promise',
                'note' => 'Cliente ofrecio cancelar ayer',
                'promised_amount' => 10.00,
                'outstanding_snapshot' => 13.00,
                'promised_at' => '2026-03-10 00:00:00',
                'created_at' => '2026-03-11 16:30:00',
                'updated_at' => '2026-03-11 16:30:00',
            ],
            [
                'tenant_id' => 10,
                'sale_receivable_id' => $upcomingReceivableId,
                'user_id' => $admin->id,
                'type' => 'promise',
                'note' => 'Cliente pagara en la fecha acordada',
                'promised_amount' => 7.00,
                'outstanding_snapshot' => 7.00,
                'promised_at' => '2026-03-13 00:00:00',
                'created_at' => '2026-03-11 16:35:00',
                'updated_at' => '2026-03-11 16:35:00',
            ],
            [
                'tenant_id' => 10,
                'sale_receivable_id' => $dueTodayReceivableId,
                'user_id' => $admin->id,
                'type' => 'promise',
                'note' => 'Cliente cumple hoy mismo',
                'promised_amount' => 11.00,
                'outstanding_snapshot' => 11.00,
                'promised_at' => '2026-03-11 00:00:00',
                'created_at' => '2026-03-11 16:40:00',
                'updated_at' => '2026-03-11 16:40:00',
            ],
        ]);

        $dailySupplierId = DB::table('suppliers')->insertGetId([
            'tenant_id' => 10,
            'tax_id' => '20101010101',
            'name' => 'Proveedor Daily',
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $dailyReceiptDueToday = DB::table('purchase_receipts')->insertGetId([
            'tenant_id' => 10,
            'supplier_id' => $dailySupplierId,
            'purchase_order_id' => null,
            'user_id' => $admin->id,
            'reference' => 'PUR-DAILY-TODAY',
            'status' => 'received',
            'total_amount' => 16.00,
            'received_at' => '2026-03-11 08:10:00',
            'created_at' => '2026-03-11 08:10:00',
            'updated_at' => '2026-03-11 08:10:00',
        ]);

        $dailyReceiptOverdue = DB::table('purchase_receipts')->insertGetId([
            'tenant_id' => 10,
            'supplier_id' => $dailySupplierId,
            'purchase_order_id' => null,
            'user_id' => $admin->id,
            'reference' => 'PUR-DAILY-OVERDUE',
            'status' => 'received',
            'total_amount' => 22.00,
            'received_at' => '2026-03-10 08:10:00',
            'created_at' => '2026-03-10 08:10:00',
            'updated_at' => '2026-03-10 08:10:00',
        ]);

        $dailyReceiptUpcoming = DB::table('purchase_receipts')->insertGetId([
            'tenant_id' => 10,
            'supplier_id' => $dailySupplierId,
            'purchase_order_id' => null,
            'user_id' => $admin->id,
            'reference' => 'PUR-DAILY-UPCOMING',
            'status' => 'received',
            'total_amount' => 19.00,
            'received_at' => '2026-03-11 16:10:00',
            'created_at' => '2026-03-11 16:10:00',
            'updated_at' => '2026-03-11 16:10:00',
        ]);

        DB::table('purchase_payables')->insert([
            [
                'tenant_id' => 10,
                'supplier_id' => $dailySupplierId,
                'purchase_receipt_id' => $dailyReceiptDueToday,
                'total_amount' => 16.00,
                'paid_amount' => 0.00,
                'outstanding_amount' => 16.00,
                'status' => 'pending',
                'due_at' => '2026-03-11 00:00:00',
                'created_at' => '2026-03-11 08:10:00',
                'updated_at' => '2026-03-11 08:10:00',
            ],
            [
                'tenant_id' => 10,
                'supplier_id' => $dailySupplierId,
                'purchase_receipt_id' => $dailyReceiptOverdue,
                'total_amount' => 22.00,
                'paid_amount' => 0.00,
                'outstanding_amount' => 22.00,
                'status' => 'pending',
                'due_at' => '2026-03-09 00:00:00',
                'created_at' => '2026-03-10 08:10:00',
                'updated_at' => '2026-03-10 08:10:00',
            ],
            [
                'tenant_id' => 10,
                'supplier_id' => $dailySupplierId,
                'purchase_receipt_id' => $dailyReceiptUpcoming,
                'total_amount' => 19.00,
                'paid_amount' => 0.00,
                'outstanding_amount' => 19.00,
                'status' => 'pending',
                'due_at' => '2026-03-15 00:00:00',
                'created_at' => '2026-03-11 16:10:00',
                'updated_at' => '2026-03-11 16:10:00',
            ],
        ]);

        $dueTodayPayableId = DB::table('purchase_payables')->where('purchase_receipt_id', $dailyReceiptDueToday)->value('id');
        $overduePayableId = DB::table('purchase_payables')->where('purchase_receipt_id', $dailyReceiptOverdue)->value('id');
        $upcomingPayableId = DB::table('purchase_payables')->where('purchase_receipt_id', $dailyReceiptUpcoming)->value('id');

        DB::table('purchase_payable_follow_ups')->insert([
            [
                'tenant_id' => 10,
                'purchase_payable_id' => $overduePayableId,
                'user_id' => $admin->id,
                'type' => 'promise',
                'note' => 'Pago proveedor vencido',
                'promised_amount' => 22.00,
                'outstanding_snapshot' => 22.00,
                'promised_at' => '2026-03-09 00:00:00',
                'created_at' => '2026-03-11 16:45:00',
                'updated_at' => '2026-03-11 16:45:00',
            ],
            [
                'tenant_id' => 10,
                'purchase_payable_id' => $dueTodayPayableId,
                'user_id' => $admin->id,
                'type' => 'promise',
                'note' => 'Pago proveedor para hoy',
                'promised_amount' => 16.00,
                'outstanding_snapshot' => 16.00,
                'promised_at' => '2026-03-11 00:00:00',
                'created_at' => '2026-03-11 16:46:00',
                'updated_at' => '2026-03-11 16:46:00',
            ],
            [
                'tenant_id' => 10,
                'purchase_payable_id' => $upcomingPayableId,
                'user_id' => $admin->id,
                'type' => 'promise',
                'note' => 'Pago proveedor programado',
                'promised_amount' => 19.00,
                'outstanding_snapshot' => 19.00,
                'promised_at' => '2026-03-14 00:00:00',
                'created_at' => '2026-03-11 16:47:00',
                'updated_at' => '2026-03-11 16:47:00',
            ],
        ]);

        $this->actingAs($admin)
            ->withHeader('X-Tenant-Id', '10')
            ->getJson('/reports/daily?date=2026-03-11')
            ->assertOk()
            ->assertJsonPath('data.tenant_id', 10)
            ->assertJsonPath('data.date', '2026-03-11')
            ->assertJsonPath('data.sales.completed_count', 3)
            ->assertJsonPath('data.sales.completed_total', 69.5)
            ->assertJsonPath('data.sales.cancelled_count', 1)
            ->assertJsonPath('data.sales.cancelled_total', 15)
            ->assertJsonPath('data.sales.by_payment_method.cash.count', 1)
            ->assertJsonPath('data.sales.by_payment_method.cash.total', 40.5)
            ->assertJsonPath('data.sales.by_payment_method.card.count', 1)
            ->assertJsonPath('data.sales.by_payment_method.card.total', 20)
            ->assertJsonPath('data.sales.by_payment_method.transfer.count', 0)
            ->assertJsonPath('data.sales.by_payment_method.transfer.total', 0)
            ->assertJsonPath('data.sales.by_payment_method.credit.count', 1)
            ->assertJsonPath('data.sales.by_payment_method.credit.total', 9)
            ->assertJsonPath('data.profitability.gross_cost_total', 33)
            ->assertJsonPath('data.profitability.gross_margin_total', 36.5)
            ->assertJsonPath('data.profitability.margin_pct', 52.52)
            ->assertJsonPath('data.profitability.top_products.0.sku', 'DASH-001')
            ->assertJsonPath('data.profitability.top_products.0.quantity_sold', 5)
            ->assertJsonPath('data.profitability.top_products.0.revenue_total', 60.5)
            ->assertJsonPath('data.vouchers.accepted_count', 1)
            ->assertJsonPath('data.vouchers.rejected_count', 1)
            ->assertJsonPath('data.vouchers.failed_count', 0)
            ->assertJsonPath('data.collections.payment_count', 1)
            ->assertJsonPath('data.collections.total_amount', 9)
            ->assertJsonPath('data.collections.by_payment_method.cash.count', 1)
            ->assertJsonPath('data.collections.by_payment_method.cash.total', 9)
            ->assertJsonPath('data.due_reminders.receivables.overdue_count', 1)
            ->assertJsonPath('data.due_reminders.receivables.overdue_amount', 13)
            ->assertJsonPath('data.due_reminders.receivables.due_today_count', 1)
            ->assertJsonPath('data.due_reminders.receivables.due_today_amount', 11)
            ->assertJsonPath('data.due_reminders.receivables.upcoming_count', 1)
            ->assertJsonPath('data.due_reminders.receivables.upcoming_amount', 7)
            ->assertJsonPath('data.due_reminders.payables.overdue_count', 1)
            ->assertJsonPath('data.due_reminders.payables.overdue_amount', 22)
            ->assertJsonPath('data.due_reminders.payables.due_today_count', 1)
            ->assertJsonPath('data.due_reminders.payables.due_today_amount', 16)
            ->assertJsonPath('data.due_reminders.payables.upcoming_count', 1)
            ->assertJsonPath('data.due_reminders.payables.upcoming_amount', 19)
            ->assertJsonPath('data.promise_compliance.receivables.broken.count', 2)
            ->assertJsonPath('data.promise_compliance.receivables.pending.count', 1)
            ->assertJsonPath('data.promise_compliance.receivables.fulfilled.count', 0)
            ->assertJsonPath('data.promise_compliance.payables.broken.count', 2)
            ->assertJsonPath('data.promise_compliance.payables.pending.count', 1)
            ->assertJsonPath('data.promise_compliance.payables.fulfilled.count', 0)
            ->assertJsonPath('data.promise_compliance.combined.broken.count', 4)
            ->assertJsonPath('data.activity.by_domain.security', 0)
            ->assertJsonPath('data.cash.opened_count', 2)
            ->assertJsonPath('data.cash.closed_count', 1)
            ->assertJsonPath('data.cash.discrepancy_total', 0.5)
            ->assertJsonPath('data.cash.movement_count', 3)
            ->assertJsonPath('data.cash.manual_in_total', 12)
            ->assertJsonPath('data.cash.manual_out_total', 4.5)
            ->assertJsonPath('data.cash.receivable_in_total', 9);
    }

    public function test_cashier_cannot_read_daily_operational_summary_without_permission(): void
    {
        $this->seedBaseCatalog();
        $cashier = $this->seedUserWithRole(10, 'CAJERO');

        $this->actingAs($cashier)
            ->withHeader('X-Tenant-Id', '10')
            ->getJson('/reports/daily?date=2026-03-11')
            ->assertStatus(403);
    }

    private function seedBaseCatalog(): void
    {
        $this->seed([
            \Database\Seeders\TenantSeeder::class,
            \Database\Seeders\RbacCatalogSeeder::class,
        ]);
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
