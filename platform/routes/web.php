<?php

use App\Http\Controllers\AssistantController;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\BillingController;
use App\Http\Controllers\CustomerController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\DriverController;
use App\Http\Controllers\GeofenceController;
use App\Http\Controllers\JourneyHistoryController;
use App\Http\Controllers\MovementAnalysisController;
use App\Http\Controllers\NotificationSettingsController;
use App\Http\Controllers\OperationsController;
use App\Http\Controllers\PlanController;
use App\Http\Controllers\ProtocolController;
use App\Http\Controllers\ReadinessController;
use App\Http\Controllers\TraccarConnectionController;
use App\Http\Controllers\TrackerSetupController;
use App\Http\Controllers\TrackingController;
use App\Http\Controllers\TrackingShareController;
use App\Http\Controllers\UserManagementController;
use App\Http\Controllers\VehicleController;
use App\Services\DeploymentHealth;
use Illuminate\Support\Facades\Route;

Route::redirect('/', '/dashboard');
Route::get('/system-health', fn (DeploymentHealth $health) => view('fleet.health', ['checks' => $health->checks()]))
    ->middleware(['auth', 'active', 'auth.session', 'can:manage-platform', 'throttle:10,1'])->name('system.health');
Route::middleware('guest')->group(function () {
    Route::view('/login', 'auth.login')->name('login');
    Route::post('/login', [AuthController::class, 'login'])->middleware('throttle:auth-ip');
    Route::view('/forgot-password', 'auth.forgot-password')->name('password.request');
    Route::post('/forgot-password', [AuthController::class, 'forgotPassword'])->middleware('throttle:password-reset')->name('password.email');
    Route::get('/reset-password/{token}', fn (string $token) => view('auth.reset-password', ['token' => $token]))->name('password.reset');
    Route::post('/reset-password', [AuthController::class, 'resetPassword'])->middleware('throttle:password-reset')->name('password.update');
});
Route::post('/paynow/result', [BillingController::class, 'webhook'])->middleware('throttle:120,1')->name('paynow.result');
Route::middleware(['auth', 'active', 'auth.session', 'subscription'])->group(function () {
    Route::get('/getting-started', ReadinessController::class)->middleware('can:manage-fleet')->name('readiness.index');
    Route::get('/notifications', [NotificationSettingsController::class, 'index'])->name('notifications.index');
    Route::post('/notifications', [NotificationSettingsController::class, 'save'])->middleware('throttle:10,1')->name('notifications.save');
    Route::get('/billing', [BillingController::class, 'index'])->name('billing.index');
    Route::post('/billing/checkout', [BillingController::class, 'checkout'])->middleware('throttle:5,1')->name('billing.checkout');
    Route::get('/billing/payments/{payment}', [BillingController::class, 'show'])->name('billing.payment');
    Route::post('/billing/payments/{payment}/check', [BillingController::class, 'check'])->middleware('throttle:10,1')->name('billing.check');
    Route::get('/audit-log', [BillingController::class, 'audit'])->middleware('can:manage-users')->name('audit.index');
    Route::get('/plans', [PlanController::class, 'index'])->middleware('can:manage-platform')->name('plans.index');
    Route::post('/plans', [PlanController::class, 'save'])->middleware('can:manage-platform')->name('plans.store');
    Route::put('/plans/{plan}', [PlanController::class, 'save'])->middleware('can:manage-platform')->name('plans.update');
    Route::get('/dashboard', DashboardController::class)->name('dashboard');
    Route::get('/geofences', [GeofenceController::class, 'index'])->middleware('can:manage-fleet')->name('geofences.index');
    Route::post('/geofences', [GeofenceController::class, 'store'])->middleware(['can:manage-fleet', 'throttle:10,1'])->name('geofences.store');
    Route::post('/geofences/{fence}/sync', [GeofenceController::class, 'sync'])->middleware(['can:manage-fleet', 'throttle:10,1'])->name('geofences.sync');
    Route::delete('/geofences/{fence}', [GeofenceController::class, 'destroy'])->middleware(['can:manage-fleet', 'throttle:10,1'])->name('geofences.destroy');
    Route::get('/journeys', [JourneyHistoryController::class, 'index'])->name('history.index');
    Route::get('/journeys/data', [JourneyHistoryController::class, 'data'])->middleware('throttle:10,1')->name('history.data');
    Route::get('/movement-analysis', [MovementAnalysisController::class, 'index'])->middleware('can:manage-fleet')->name('movement.index');
    Route::post('/movement-analysis', [MovementAnalysisController::class, 'analyze'])->middleware(['can:manage-fleet', 'throttle:3,1'])->name('movement.analyze');
    Route::view('/tracking', 'tracking')->name('tracking');
    Route::get('/users', [UserManagementController::class, 'index'])->middleware('can:manage-users')->name('users.index');
    Route::post('/users', [UserManagementController::class, 'store'])->middleware(['can:manage-users', 'throttle:20,1'])->name('users.store');
    Route::post('/users/{user}/status', [UserManagementController::class, 'status'])->middleware(['can:manage-users', 'throttle:20,1'])->name('users.status');
    Route::get('/tracker-protocols', ProtocolController::class)->middleware('can:manage-fleet')->name('protocols.index');
    Route::get('/tracker-setup', TrackerSetupController::class)->middleware('can:manage-fleet')->name('tracker.setup');
    Route::get('/assistant', AssistantController::class.'@index')->middleware('can:manage-fleet')->name('assistant.index');
    Route::post('/assistant', AssistantController::class.'@ask')->middleware(['can:manage-fleet', 'throttle:5,1'])->name('assistant.ask');
    Route::get('/vehicles', [VehicleController::class, 'index'])->name('vehicles.index');
    Route::get('/devices', [VehicleController::class, 'index'])->name('devices.index');
    Route::get('/vehicles/create', [VehicleController::class, 'create'])->name('vehicles.create');
    Route::post('/vehicles', [VehicleController::class, 'store'])->middleware('throttle:20,1')->name('vehicles.store');
    Route::get('/vehicles/{vehicle}/edit', [VehicleController::class, 'edit'])->name('vehicles.edit');
    Route::put('/vehicles/{vehicle}', [VehicleController::class, 'update'])->middleware('throttle:20,1')->name('vehicles.update');
    Route::post('/vehicles/{vehicle}/sync', [VehicleController::class, 'sync'])->middleware('throttle:10,1')->name('vehicles.sync');
    Route::post('/vehicles/{vehicle}/status', [VehicleController::class, 'status'])->middleware('throttle:20,1')->name('vehicles.status');
    Route::get('/customers', [CustomerController::class, 'index'])->middleware('can:manage-platform')->name('customers.index');
    Route::get('/customers/{customer}/edit', [CustomerController::class, 'edit'])->middleware('can:manage-platform')->name('customers.edit');
    Route::put('/customers/{customer}', [CustomerController::class, 'update'])->middleware(['can:manage-platform', 'throttle:20,1'])->name('customers.update');
    Route::post('/customers/{customer}/status', [CustomerController::class, 'status'])->middleware(['can:manage-platform', 'throttle:20,1'])->name('customers.status');
    Route::post('/customers', [CustomerController::class, 'store'])->middleware(['can:manage-platform', 'throttle:20,1'])->name('customers.store');
    Route::get('/drivers', [DriverController::class, 'index'])->middleware('can:manage-fleet')->name('drivers.index');
    Route::post('/drivers', [DriverController::class, 'store'])->middleware(['can:manage-fleet', 'throttle:20,1'])->name('drivers.store');
    Route::get('/tracking/positions', [TrackingController::class, 'positions'])->middleware('throttle:60,1')->name('tracking.positions');
    Route::post('/logout', [AuthController::class, 'logout'])->name('logout');
    Route::post('/settings/traccar/check', TraccarConnectionController::class)
        ->middleware(['can:manage-platform', 'throttle:traccar-check'])->name('traccar.check');
});

Route::get('/share/{token}', [TrackingShareController::class, 'show'])->middleware('throttle:30,1')->name('share.show');
Route::middleware(['auth', 'active', 'auth.session', 'subscription'])->group(function () {
    Route::get('/fleet-insights', [OperationsController::class, 'snapshot'])->middleware('throttle:12,1')->name('operations.snapshot');
    Route::middleware('can:manage-fleet')->group(function () {
        Route::get('/operations', [OperationsController::class, 'index'])->name('operations.index');
        Route::post('/operations/fuel', [OperationsController::class, 'fuel'])->middleware('throttle:20,1')->name('operations.fuel');
        Route::post('/operations/maintenance', [OperationsController::class, 'maintenance'])->middleware('throttle:20,1')->name('operations.maintenance');
        Route::post('/operations/maintenance/{task}/complete', [OperationsController::class, 'complete'])->name('operations.complete');
        Route::get('/operations/maintenance/{task}/document', [OperationsController::class, 'document'])->name('operations.document');
        Route::get('/operations/report', [OperationsController::class, 'report'])->middleware('throttle:5,1')->name('operations.report');
        Route::post('/operations/schedule', [OperationsController::class, 'schedule'])->name('operations.schedule');
        Route::post('/operations/shares', [TrackingShareController::class, 'store'])->middleware('throttle:10,1')->name('shares.store');
        Route::post('/operations/shares/{share}/revoke', [TrackingShareController::class, 'revoke'])->name('shares.revoke');
    });
});
