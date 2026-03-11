<?php

use App\Services\Billing\OutboxDispatchService;
use App\Services\Billing\VoucherService;
use App\Services\Cash\CashSessionService;
use App\Services\Inventory\InventorySetupService;
use App\Services\Inventory\LotControlService;
use App\Services\Inventory\StockMovementService;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\DB;
use App\Services\Sales\PosSaleService;
use App\Services\Sales\SaleApprovalService;

Route::get('/', function () {
    return view('welcome');
});

Route::middleware('tenant.context')->get('/tenant/ping', function () {
    return response()->json([
        'ok' => true,
        'tenant' => app('currentTenantId'),
    ]);
});

Route::middleware(['auth', 'tenant.context', 'tenant.access'])->group(function () {
    Route::get('/pos/sale', fn () => response()->json(['ok' => true, 'flow' => 'sale']))
        ->middleware('perm:pos.sale.execute');

    Route::post('/pos/sales', function (PosSaleService $service) {
        $payload = request()->all();

        if (isset($payload['items'])) {
            request()->validate([
                'items' => ['required', 'array', 'min:1'],
                'items.*.lot_id' => ['nullable', 'integer'],
                'items.*.product_id' => ['nullable', 'integer'],
                'items.*.quantity' => ['required', 'integer', 'min:1'],
                'items.*.unit_price' => ['required', 'numeric', 'min:0'],
            ]);

            $items = array_map(fn (array $item) => [
                'lot_id' => $item['lot_id'] ?? null,
                'product_id' => $item['product_id'] ?? null,
                'quantity' => $item['quantity'],
                'unit_price' => $item['unit_price'],
                'prescription_code' => $item['prescription_code'] ?? null,
                'approval_code' => $item['approval_code'] ?? null,
            ], $payload['items']);
        } else {
            $single = request()->validate([
                'lot_id' => ['required', 'integer'],
                'quantity' => ['required', 'integer', 'min:1'],
                'unit_price' => ['required', 'numeric', 'min:0'],
                'prescription_code' => ['nullable', 'string'],
                'approval_code' => ['nullable', 'string'],
            ]);

            $items = [[
                'lot_id' => (int) $single['lot_id'],
                'quantity' => (int) $single['quantity'],
                'unit_price' => (float) $single['unit_price'],
                'prescription_code' => $single['prescription_code'] ?? null,
                'approval_code' => $single['approval_code'] ?? null,
            ]];
        }

        $result = $service->execute(
            (int) request()->attributes->get('tenant_id'),
            (int) optional(request()->user())->id,
            $items,
        );

        return response()->json(['data' => $result]);
    })->middleware('perm:pos.sale.execute');

    Route::get('/pos/approve', fn () => response()->json(['ok' => true, 'flow' => 'approve']))
        ->middleware('perm:pos.sale.approve');

    Route::post('/pos/approvals', function (SaleApprovalService $service) {
        $payload = request()->validate([
            'product_id' => ['required', 'integer'],
            'reason' => ['required', 'string'],
        ]);

        $result = $service->create(
            (int) request()->attributes->get('tenant_id'),
            (int) optional(request()->user())->id,
            (int) $payload['product_id'],
            (string) $payload['reason'],
        );

        return response()->json(['data' => $result]);
    })->middleware('perm:pos.sale.approve');

    Route::post('/cash/sessions/open', function (CashSessionService $service) {
        $payload = request()->validate([
            'opening_amount' => ['required', 'numeric', 'min:0'],
        ]);

        $result = $service->open(
            (int) request()->attributes->get('tenant_id'),
            (int) optional(request()->user())->id,
            (float) $payload['opening_amount'],
        );

        return response()->json(['data' => $result]);
    })->middleware('perm:cash.session.open');

    Route::get('/cash/sessions/current', function (CashSessionService $service) {
        $result = $service->current((int) request()->attributes->get('tenant_id'));

        return response()->json(['data' => $result]);
    })->middleware('perm:cash.session.read');

    Route::get('/cash/sessions', function (CashSessionService $service) {
        $result = $service->history((int) request()->attributes->get('tenant_id'));

        return response()->json(['data' => $result]);
    })->middleware('perm:cash.session.read');

    Route::get('/cash/sessions/{session}', function (int $session, CashSessionService $service) {
        $result = $service->detail(
            (int) request()->attributes->get('tenant_id'),
            $session,
        );

        return response()->json(['data' => $result]);
    })->middleware('perm:cash.session.read');

    Route::post('/cash/sessions/current/close', function (CashSessionService $service) {
        $payload = request()->validate([
            'counted_amount' => ['required', 'numeric', 'min:0'],
        ]);

        $result = $service->close(
            (int) request()->attributes->get('tenant_id'),
            (int) optional(request()->user())->id,
            (float) $payload['counted_amount'],
        );

        return response()->json(['data' => $result]);
    })->middleware('perm:cash.session.close');

    Route::get('/stock/move', fn () => response()->json(['ok' => true, 'flow' => 'stock']))
        ->middleware('perm:stock.move.create');

    Route::post('/stock/movements', function (StockMovementService $service) {
        $payload = request()->validate([
            'lot_id' => ['required', 'integer'],
            'type' => ['required', 'string'],
            'quantity' => ['required', 'integer', 'min:1'],
            'reference' => ['required', 'string'],
        ]);

        $result = $service->create(
            (int) request()->attributes->get('tenant_id'),
            (int) $payload['lot_id'],
            (string) $payload['type'],
            (int) $payload['quantity'],
            (string) $payload['reference'],
        );

        return response()->json(['data' => $result]);
    })->middleware('perm:stock.move.create');

    Route::get('/rbac/permissions', fn () => response()->json(['ok' => true, 'flow' => 'rbac-permissions']))
        ->middleware('perm:rbac.permission.manage');

    Route::get('/inventory/products', function () {
        $tenantId = (int) request()->attributes->get('tenant_id');

        $products = DB::table('products')
            ->where('tenant_id', $tenantId)
            ->orderBy('sku')
            ->get(['id', 'tenant_id', 'sku', 'name', 'status', 'is_controlled']);

        return response()->json(['data' => $products]);
    })->middleware('perm:inventory.product.read');

    Route::post('/inventory/products', function (InventorySetupService $service) {
        $payload = request()->validate([
            'sku' => ['required', 'string'],
            'name' => ['required', 'string'],
            'is_controlled' => ['required', 'boolean'],
        ]);

        $result = $service->createProduct(
            (int) request()->attributes->get('tenant_id'),
            (string) $payload['sku'],
            (string) $payload['name'],
            (bool) $payload['is_controlled'],
        );

        return response()->json(['data' => $result]);
    })->middleware('perm:inventory.product.create');

    Route::get('/inventory/lots/{lot}', function (int $lot) {
        $tenantId = (int) request()->attributes->get('tenant_id');

        $lotData = DB::table('lots')
            ->join('products', 'products.id', '=', 'lots.product_id')
            ->where('lots.id', $lot)
            ->where('lots.tenant_id', $tenantId)
            ->first([
                'lots.id',
                'lots.tenant_id',
                'lots.code',
                'lots.stock_quantity',
                'lots.status',
                'products.sku as product_sku',
            ]);

        if ($lotData === null) {
            abort(404, 'Lot not found.');
        }

        return response()->json(['data' => $lotData]);
    })->middleware('perm:inventory.lot.read');

    Route::post('/inventory/lots', function (InventorySetupService $service) {
        $payload = request()->validate([
            'product_id' => ['required', 'integer'],
            'code' => ['required', 'string'],
            'expires_at' => ['required', 'date'],
            'stock_quantity' => ['required', 'integer', 'min:0'],
        ]);

        $result = $service->createLot(
            (int) request()->attributes->get('tenant_id'),
            (int) $payload['product_id'],
            (string) $payload['code'],
            (string) $payload['expires_at'],
            (int) $payload['stock_quantity'],
        );

        return response()->json(['data' => $result]);
    })->middleware('perm:inventory.lot.create');

    Route::post('/inventory/lots/{lot}/immobilize', function (int $lot, LotControlService $service) {
        $payload = request()->validate([
            'reason' => ['required', 'string'],
        ]);

        $result = $service->immobilize(
            (int) request()->attributes->get('tenant_id'),
            $lot,
            (string) $payload['reason'],
        );

        return response()->json(['data' => $result]);
    })->middleware('perm:inventory.lot.create');

    Route::post('/billing/vouchers', function (VoucherService $service) {
        $payload = request()->validate([
            'sale_id' => ['required', 'integer'],
            'type' => ['required', 'string'],
        ]);

        $result = $service->createFromSale(
            (int) request()->attributes->get('tenant_id'),
            (int) $payload['sale_id'],
            (string) $payload['type'],
        );

        return response()->json(['data' => $result]);
    })->middleware('perm:billing.voucher.issue');

    Route::get('/billing/vouchers/{voucher}', function (int $voucher) {
        $tenantId = (int) request()->attributes->get('tenant_id');

        $voucherData = DB::table('electronic_vouchers')
            ->join('sales', 'sales.id', '=', 'electronic_vouchers.sale_id')
            ->leftJoin('outbox_events', function ($join) {
                $join->on('outbox_events.aggregate_id', '=', 'electronic_vouchers.id')
                    ->where('outbox_events.aggregate_type', '=', 'electronic_voucher');
            })
            ->where('electronic_vouchers.id', $voucher)
            ->where('electronic_vouchers.tenant_id', $tenantId)
            ->first([
                'electronic_vouchers.id',
                'electronic_vouchers.tenant_id',
                'electronic_vouchers.sale_id',
                'electronic_vouchers.type',
                'electronic_vouchers.series',
                'electronic_vouchers.number',
                'electronic_vouchers.status',
                'electronic_vouchers.sunat_ticket',
                'electronic_vouchers.rejection_reason',
                'sales.reference as sale_reference',
                'outbox_events.id as outbox_event_id',
            ]);

        if ($voucherData === null) {
            abort(404, 'Voucher not found.');
        }

        return response()->json(['data' => $voucherData]);
    })->middleware('perm:billing.voucher.read');

    Route::post('/billing/outbox/dispatch', function (OutboxDispatchService $service) {
        $payload = request()->validate([
            'simulate_result' => ['nullable', 'string'],
        ]);

        $result = $service->dispatchNext(
            (int) request()->attributes->get('tenant_id'),
            (string) ($payload['simulate_result'] ?? 'accepted'),
        );

        $status = (int) ($result['http_status'] ?? 200);
        unset($result['http_status']);

        return response()->json(['data' => $result], $status);
    })->middleware('perm:billing.outbox.dispatch');

    Route::post('/billing/outbox/{event}/retry', function (int $event, OutboxDispatchService $service) {
        $result = $service->retryFailed(
            (int) request()->attributes->get('tenant_id'),
            $event,
        );

        return response()->json(['data' => $result]);
    })->middleware('perm:billing.outbox.dispatch');

    Route::get('/billing/outbox/{event}/attempts', function (int $event) {
        $tenantId = (int) request()->attributes->get('tenant_id');

        $eventExists = DB::table('outbox_events')
            ->where('id', $event)
            ->where('tenant_id', $tenantId)
            ->exists();

        if (! $eventExists) {
            abort(404, 'Outbox event not found.');
        }

        $attempts = DB::table('outbox_attempts')
            ->where('outbox_event_id', $event)
            ->orderBy('id')
            ->get(['id', 'outbox_event_id', 'status', 'sunat_ticket', 'error_message', 'created_at']);

        return response()->json(['data' => $attempts]);
    })->middleware('perm:billing.outbox.read');
});
