<?php

use App\Http\Controllers\AnalysisController;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\DeviceController;
use App\Http\Controllers\LogController;
use App\Http\Controllers\MonitoringController;
use App\Http\Controllers\SettingController;
use Illuminate\Support\Facades\Route;

// Authentication Routes
Route::get('/login', [AuthController::class, 'showLoginForm'])->name('login');
Route::post('/login', [AuthController::class, 'login'])->name('login.attempt');
Route::post('/logout', [AuthController::class, 'logout'])->middleware('auth')->name('logout');

// Monitoring Pages (Public Read-Only when enabled, or Admin-only when disabled)
Route::middleware('dashboard.access')->group(function () {
    Route::get('/', [DashboardController::class, 'index'])->name('dashboard');
    Route::get('/dashboard/latest', [DashboardController::class, 'latest'])->name('dashboard.latest');

    Route::get('/monitoring/realtime', [MonitoringController::class, 'realtime'])->name('monitoring.realtime');
    Route::get('/monitoring/history', [MonitoringController::class, 'history'])->name('monitoring.history');
    Route::get('/monitoring/chart-data', [MonitoringController::class, 'chartData'])->name('monitoring.chart-data');
    Route::get('/logs', [LogController::class, 'index'])->name('logs.index');
    Route::get('/logs/export/csv', [LogController::class, 'exportCsv'])->name('logs.export.csv');
    Route::get('/logs/export/pdf', [LogController::class, 'exportPdf'])->name('logs.export.pdf');

    Route::get('/analysis', [AnalysisController::class, 'index'])->name('analysis.index');
});

// Admin-Only Management Pages
Route::middleware('auth')->group(function () {
    Route::get('/devices', [DeviceController::class, 'index'])->name('devices.index');
    Route::post('/devices', [DeviceController::class, 'store'])->name('devices.store');
    Route::put('/devices/{device}', [DeviceController::class, 'update'])->name('devices.update');
    Route::post('/devices/{device}/rotate-token', [DeviceController::class, 'rotateToken'])->name('devices.rotate-token');

    Route::get('/settings', [SettingController::class, 'index'])->name('settings.index');
    Route::put('/settings/system', [SettingController::class, 'updateSystem'])->name('settings.system');
    Route::put('/settings/account', [SettingController::class, 'updateAccount'])->name('settings.account');
    Route::post('/settings/clear-readings', [SettingController::class, 'clearReadings'])->name('settings.clear-readings');
});
