<?php

use App\Modules\Branches\Http\Controllers\Api\BranchApiController;
use App\Modules\Catalog\Http\Controllers\Api\CatalogProductController;
use App\Modules\Delivery\Http\Controllers\DeliveryAreaController;
use Illuminate\Support\Facades\Route;

/*
 * API customer publik (tanpa auth; rate limit customer-public per IP).
 * Larangan: tidak ada endpoint yang menuntut harga/total dari client.
 */
Route::prefix('v1')->group(function (): void {
    Route::middleware('throttle:customer-public')->group(function (): void {
        Route::get('/branches', [BranchApiController::class, 'index']);
        Route::get('/products', [CatalogProductController::class, 'index']);
        Route::get('/delivery/areas', [DeliveryAreaController::class, 'index']);
    });
});
