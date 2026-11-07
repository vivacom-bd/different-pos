<?php

declare(strict_types=1);

use App\Http\Controllers\Admin\AuditController;
use App\Http\Controllers\Admin\BranchController;
use App\Http\Controllers\Admin\BusinessSettingsController;
use App\Http\Controllers\Admin\CourierController;
use App\Http\Controllers\Admin\PaymentMethodController;
use App\Http\Controllers\Admin\RegisterController;
use App\Http\Controllers\Admin\RoleController;
use App\Http\Controllers\Admin\TaxRateController;
use App\Http\Controllers\Admin\UnitController;
use App\Http\Controllers\Admin\UserController;
use App\Http\Controllers\Backup\BackupController;
use Illuminate\Support\Facades\Route;

/* Business administration under /app/settings (02_AppFlow §2 rows 100-106). Loaded inside the branch-selected /app group. */

Route::prefix('settings')->group(function (): void {
    Route::middleware('can:settings.manage')->group(function (): void {
        Route::get('business', [BusinessSettingsController::class, 'edit'])->name('settings.business');
        Route::put('business', [BusinessSettingsController::class, 'update'])->name('settings.business.update');
        Route::post('business/logo', [BusinessSettingsController::class, 'updateLogo'])->name('settings.business.logo');
        Route::delete('business/logo', [BusinessSettingsController::class, 'destroyLogo'])->name('settings.business.logo.destroy');

        Route::get('branches', [BranchController::class, 'index'])->name('branches.index');
        Route::post('branches', [BranchController::class, 'store'])->name('branches.store');
        Route::put('branches/{branch}', [BranchController::class, 'update'])->whereNumber('branch')->name('branches.update');

        Route::get('registers', [RegisterController::class, 'index'])->name('registers.index');
        Route::post('registers', [RegisterController::class, 'store'])->name('registers.store');
        Route::put('registers/{register}', [RegisterController::class, 'update'])->whereNumber('register')->name('registers.update');

        Route::get('payment-methods', [PaymentMethodController::class, 'index'])->name('payment-methods.index');
        Route::post('payment-methods', [PaymentMethodController::class, 'store'])->name('payment-methods.store');
        Route::put('payment-methods/{method}', [PaymentMethodController::class, 'update'])->whereNumber('method')->name('payment-methods.update');

        Route::get('couriers', [CourierController::class, 'index'])->name('couriers.index');
        Route::post('couriers', [CourierController::class, 'store'])->name('couriers.store');
        Route::put('couriers/{courier}', [CourierController::class, 'update'])->whereNumber('courier')->name('couriers.update');
        Route::post('couriers/{courier}/default', [CourierController::class, 'makeDefault'])->whereNumber('courier')->name('couriers.default');
        Route::delete('couriers/{courier}', [CourierController::class, 'destroy'])->whereNumber('courier')->name('couriers.destroy');

        Route::get('taxes', [TaxRateController::class, 'index'])->name('taxes.index');
        Route::post('taxes', [TaxRateController::class, 'store'])->name('taxes.store');
        Route::put('taxes/{tax}', [TaxRateController::class, 'update'])->whereNumber('tax')->name('taxes.update');

        Route::get('units', [UnitController::class, 'index'])->name('units.index');
        Route::post('units', [UnitController::class, 'store'])->name('units.store');
        Route::put('units/{unit}', [UnitController::class, 'update'])->whereNumber('unit')->name('units.update');
    });

    Route::middleware('can:users.manage')->group(function (): void {
        Route::get('users', [UserController::class, 'index'])->name('users.index');
        Route::get('users/create', [UserController::class, 'create'])->name('users.create');
        Route::post('users', [UserController::class, 'store'])->name('users.store');
        Route::get('users/{user}/edit', [UserController::class, 'edit'])->whereNumber('user')->name('users.edit');
        Route::put('users/{user}', [UserController::class, 'update'])->whereNumber('user')->name('users.update');
        Route::post('users/{user}/deactivate', [UserController::class, 'deactivate'])->whereNumber('user')->name('users.deactivate');
        Route::post('users/{user}/reactivate', [UserController::class, 'reactivate'])->whereNumber('user')->name('users.reactivate');
        Route::post('users/{user}/pin', [UserController::class, 'resetPin'])->whereNumber('user')->middleware('throttle:20,1')->name('users.pin.reset');

        Route::get('roles', [RoleController::class, 'index'])->name('roles.index');
        Route::post('roles', [RoleController::class, 'store'])->name('roles.store');
        Route::put('roles/{role}', [RoleController::class, 'update'])->whereNumber('role')->name('roles.update');
        Route::delete('roles/{role}', [RoleController::class, 'destroy'])->whereNumber('role')->name('roles.destroy');
    });

    Route::get('audit', [AuditController::class, 'index'])->middleware('can:audit.view')->name('settings.audit');

    // Installation backup: history for backup.view, run + download for backup.run (Owner only by default).
    Route::get('backups', [BackupController::class, 'index'])->name('settings.backups');
    Route::post('backups', [BackupController::class, 'store'])->middleware('throttle:6,1')->name('settings.backups.run');
    Route::get('backups/{backup}/download', [BackupController::class, 'download'])->whereNumber('backup')->name('settings.backups.download');
});
