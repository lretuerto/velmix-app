<?php

use App\Services\Inventory\InventorySetupService;
use App\Services\Inventory\StockMovementService;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\DB;
use App\Services\Sales\PosSaleService;

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
            ], $payload['items']);
        } else {
            $single = request()->validate([
                'lot_id' => ['required', 'integer'],
                'quantity' => ['required', 'integer', 'min:1'],
                'unit_price' => ['required', 'numeric', 'min:0'],
            ]);

            $items = [[
                'lot_id' => (int) $single['lot_id'],
                'quantity' => (int) $single['quantity'],
                'unit_price' => (float) $single['unit_price'],
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
});
