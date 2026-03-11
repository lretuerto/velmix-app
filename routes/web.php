<?php

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
        $payload = request()->validate([
            'lot_id' => ['required', 'integer'],
            'quantity' => ['required', 'integer', 'min:1'],
            'unit_price' => ['required', 'numeric', 'min:0'],
        ]);

        $result = $service->execute(
            (int) request()->attributes->get('tenant_id'),
            (int) optional(request()->user())->id,
            (int) $payload['lot_id'],
            (int) $payload['quantity'],
            (float) $payload['unit_price'],
        );

        return response()->json(['data' => $result]);
    })->middleware('perm:pos.sale.execute');

    Route::get('/pos/approve', fn () => response()->json(['ok' => true, 'flow' => 'approve']))
        ->middleware('perm:pos.sale.approve');

    Route::get('/stock/move', fn () => response()->json(['ok' => true, 'flow' => 'stock']))
        ->middleware('perm:stock.move.create');

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
});
