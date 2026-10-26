<?php

use App\Http\Controllers\Admin\StaffController;
use App\Http\Controllers\Auth\LoginController;
use App\Modules\Branches\Http\Controllers\BranchController;
use App\Modules\Catalog\Http\Controllers\CategoryController;
use App\Modules\Catalog\Http\Controllers\ModifierGroupController;
use App\Modules\Catalog\Http\Controllers\ProductController;
use App\Modules\Identity\Enums\Role;
use App\Modules\Ordering\Http\Controllers\PosController;
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
});

Route::middleware(['auth', 'role:owner'])->prefix('admin')->group(function () {
    Route::inertia('/', 'admin/Dashboard')->name('admin.dashboard');

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
