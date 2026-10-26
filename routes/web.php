<?php

use App\Http\Controllers\Admin\DashboardController;
use App\Http\Controllers\Admin\StaffController;
use App\Http\Controllers\Auth\LoginController;
use App\Modules\Branches\Http\Controllers\BranchController;
use App\Modules\Catalog\Http\Controllers\CategoryController;
use App\Modules\Catalog\Http\Controllers\ModifierGroupController;
use App\Modules\Catalog\Http\Controllers\ProductController;
use App\Modules\Identity\Enums\Role;
use App\Modules\Inventory\Http\Controllers\IngredientController;
use App\Modules\Inventory\Http\Controllers\StockCountController;
use App\Modules\Inventory\Http\Controllers\StockMovementController;
use App\Modules\Kitchen\Http\Controllers\KitchenController;
use App\Modules\Ordering\Http\Controllers\OrderController;
use App\Modules\Ordering\Http\Controllers\PosController;
use App\Modules\Payments\Http\Controllers\PaymentController;
use Illuminate\Support\Facades\Route;

Route::get('/health', fn () => response()->json(['status' => 'ok']));

Route::get('/', function () {
    $user = auth()->user();

    if (! $user) {
        return redirect('/login');
    }

    return redirect(match ($user->role()) {
        Role::Owner => '/admin',
        Role::Cashier => '/pos',
        Role::Kitchen => '/kitchen',
    });
})->name('home');

Route::middleware('guest')->group(function () {
    Route::get('/login', [LoginController::class, 'create'])->name('login');
    Route::post('/login', [LoginController::class, 'store']);
});

Route::post('/logout', [LoginController::class, 'destroy'])
    ->middleware('auth')
    ->name('logout');

Route::middleware(['auth', 'role:cashier,owner'])->group(function () {
    Route::get('/pos', [PosController::class, 'index'])->name('pos.index');
    Route::post('/pos/orders', [PosController::class, 'store'])->name('pos.orders.store');
    Route::get('/pos/orders', [OrderController::class, 'index'])->name('pos.orders.index');

    Route::post('/orders/{order}/pay', [PaymentController::class, 'store'])->name('orders.pay');
    Route::post('/orders/{order}/cancel', [OrderController::class, 'cancel'])->name('orders.cancel');
    Route::post('/orders/{order}/void', [OrderController::class, 'void'])->name('orders.void');
});

Route::middleware(['auth', 'role:kitchen,owner'])->group(function () {
    Route::get('/kitchen', [KitchenController::class, 'index'])->name('kitchen.index');
    Route::get('/kitchen/orders', [KitchenController::class, 'orders'])->name('kitchen.orders');
    Route::patch('/kitchen/orders/{order}/status', [KitchenController::class, 'updateStatus'])->name('kitchen.orders.status');

    Route::get('/kitchen/stock', [StockMovementController::class, 'kitchenIndex'])->name('kitchen.stock.index');
    Route::post('/kitchen/stock/waste', [StockMovementController::class, 'kitchenWaste'])->name('kitchen.stock.waste');
    Route::post('/kitchen/stock/counts', [StockCountController::class, 'kitchenStore'])->name('kitchen.stock.counts');
});

Route::middleware(['auth', 'role:owner'])->prefix('admin')->group(function () {
    Route::get('/', [DashboardController::class, 'index'])->name('admin.dashboard');

    Route::prefix('stock')->name('admin.stock.')->group(function () {
        Route::get('/ingredients', [IngredientController::class, 'index'])->name('ingredients.index');
        Route::post('/ingredients', [IngredientController::class, 'store'])->name('ingredients.store');
        Route::get('/movements', [StockMovementController::class, 'index'])->name('movements.index');
        Route::post('/movements', [StockMovementController::class, 'store'])->name('movements.store');
        Route::get('/counts', [StockCountController::class, 'index'])->name('counts.index');
        Route::post('/counts', [StockCountController::class, 'store'])->name('counts.store');
    });

    Route::get('/staff', [StaffController::class, 'index'])->name('admin.staff.index');
    Route::post('/staff', [StaffController::class, 'store'])->name('admin.staff.store');
    Route::patch('/staff/{staff}', [StaffController::class, 'update'])->name('admin.staff.update');

    Route::get('/branches', [BranchController::class, 'index'])->name('admin.branches.index');
    Route::post('/branches', [BranchController::class, 'store'])->name('admin.branches.store');
    Route::patch('/branches/{branch}', [BranchController::class, 'update'])->name('admin.branches.update');

    Route::prefix('catalog')->name('admin.catalog.')->group(function () {
        Route::get('/categories', [CategoryController::class, 'index'])->name('categories.index');
        Route::post('/categories', [CategoryController::class, 'store'])->name('categories.store');
        Route::patch('/categories/{category}', [CategoryController::class, 'update'])->name('categories.update');

        Route::get('/products', [ProductController::class, 'index'])->name('products.index');
        Route::get('/products/create', [ProductController::class, 'create'])->name('products.create');
        Route::post('/products', [ProductController::class, 'store'])->name('products.store');
        Route::get('/products/{product}/edit', [ProductController::class, 'edit'])->name('products.edit');
        Route::patch('/products/{product}', [ProductController::class, 'update'])->name('products.update');

        Route::get('/modifier-groups', [ModifierGroupController::class, 'index'])->name('modifier-groups.index');
        Route::post('/modifier-groups', [ModifierGroupController::class, 'store'])->name('modifier-groups.store');
        Route::patch('/modifier-groups/{modifierGroup}', [ModifierGroupController::class, 'update'])->name('modifier-groups.update');
    });
});
