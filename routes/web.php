<?php

use App\Http\Controllers\Admin\StaffController;
use App\Http\Controllers\Auth\LoginController;
use App\Modules\Branches\Http\Controllers\BranchController;
use App\Modules\Identity\Enums\Role;
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

Route::middleware(['auth', 'role:owner'])->prefix('admin')->group(function () {
    Route::inertia('/', 'admin/Dashboard')->name('admin.dashboard');

    Route::get('/staff', [StaffController::class, 'index'])->name('admin.staff.index');
    Route::post('/staff', [StaffController::class, 'store'])->name('admin.staff.store');
    Route::patch('/staff/{staff}', [StaffController::class, 'update'])->name('admin.staff.update');

    Route::get('/branches', [BranchController::class, 'index'])->name('admin.branches.index');
    Route::post('/branches', [BranchController::class, 'store'])->name('admin.branches.store');
    Route::patch('/branches/{branch}', [BranchController::class, 'update'])->name('admin.branches.update');
});
