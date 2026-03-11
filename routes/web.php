<?php

use Illuminate\Support\Facades\Route;

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

    Route::get('/pos/approve', fn () => response()->json(['ok' => true, 'flow' => 'approve']))
        ->middleware('perm:pos.sale.approve');

    Route::get('/stock/move', fn () => response()->json(['ok' => true, 'flow' => 'stock']))
        ->middleware('perm:stock.move.create');

    Route::get('/rbac/permissions', fn () => response()->json(['ok' => true, 'flow' => 'rbac-permissions']))
        ->middleware('perm:rbac.permission.manage');
});
