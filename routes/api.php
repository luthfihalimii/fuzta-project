<?php

use App\Modules\Branches\Http\Controllers\Api\BranchApiController;
use App\Modules\Catalog\Http\Controllers\Api\CatalogProductController;
use App\Modules\Customers\Http\Controllers\Api\AddressController;
use App\Modules\Customers\Http\Controllers\Api\AuthController;
use App\Modules\Customers\Http\Controllers\Api\MeController;
use App\Modules\Customers\Http\Controllers\Api\OrderHistoryController;
use App\Modules\Delivery\Http\Controllers\BiteshipWebhookController;
use App\Modules\Delivery\Http\Controllers\DeliveryAreaController;
use App\Modules\Ordering\Http\Controllers\Api\CheckoutController;
use App\Modules\Ordering\Http\Controllers\Api\CustomerOrderController;
use App\Modules\Payments\Http\Controllers\Api\DokuWebhookController;
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
        Route::get('/orders/{token}', [CustomerOrderController::class, 'show']);
    });

    // Mutasi lebih ketat: 10/menit per IP (plan Task 3) — throttle SEGALA
    // request (termasuk token invalid) sebelum auth dijudge.
    // customer.auth:optional: guest tetap jalan; bearer invalid/expired
    // tetap 401 (middleware), bearer valid menghubungkan order ke akun.
    Route::middleware(['throttle:customer-checkout', 'customer.auth:optional'])->group(function (): void {
        Route::post('/checkout/quote', [CheckoutController::class, 'quote']);
        Route::post('/orders', [CheckoutController::class, 'store']);
    });

    // Akun customer: daftar & login (5/menit per IP).
    Route::middleware('throttle:customer-auth')->group(function (): void {
        Route::post('/auth/register', [AuthController::class, 'register']);
        Route::post('/auth/login', [AuthController::class, 'login']);
    });

    // customer.auth dulu agar limiter customer-me bisa keyed by customer id.
    Route::post('/auth/logout', [AuthController::class, 'logout'])
        ->middleware(['customer.auth', 'throttle:customer-me']);

    // Profil & alamat tersimpan: urutan middleware sama — atribut
    // customer harus ada sebelum limiter per customer id dijalankan.
    Route::middleware(['customer.auth', 'throttle:customer-me'])->group(function (): void {
        Route::get('/me', [MeController::class, 'show']);
        Route::patch('/me', [MeController::class, 'update']);
        Route::get('/me/addresses', [AddressController::class, 'index']);
        Route::post('/me/addresses', [AddressController::class, 'store']);
        Route::patch('/me/addresses/{address}', [AddressController::class, 'update'])->whereNumber('address');
        Route::delete('/me/addresses/{address}', [AddressController::class, 'destroy'])->whereNumber('address');
        Route::get('/me/orders', OrderHistoryController::class);
    });

    // Webhook DOKU: tanpa throttle/auth — verifikasi signature HMAC (di
    // controller) adalah gerbang. Throttle per IP berisiko memblokir retry
    // notifikasi sah dari IP DOKU.
    Route::post('/webhooks/doku', DokuWebhookController::class);

    // Webhook Biteship: pola sama — verifikasi header signature (di
    // controller) adalah gerbang; event boleh berulang (handler idempotent).
    Route::post('/webhooks/biteship', BiteshipWebhookController::class);
});
