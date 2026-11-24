<?php

use App\Http\Controllers\Api\SensorDataController;
use Illuminate\Support\Facades\Route;

Route::get('/health', [SensorDataController::class, 'health'])->name('api.health');

Route::post('/sensor-data', [SensorDataController::class, 'store'])
    ->middleware(['device.auth', 'throttle:120,1'])
    ->name('api.sensor-data.store');
