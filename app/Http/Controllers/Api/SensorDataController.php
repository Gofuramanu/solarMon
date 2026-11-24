<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreSensorReadingRequest;
use App\Models\Device;
use App\Models\SensorReading;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

class SensorDataController extends Controller
{
    /**
     * Lightweight API health check endpoint for ESP32 connectivity testing.
     */
    public function health(): JsonResponse
    {
        return response()->json([
            'status' => 'ok',
            'service' => 'Solar Panel Monitoring REST API',
            'server_time' => now()->toIso8601String(),
        ]);
    }

    /**
     * Validate, authenticate, and persist sensor telemetry from ESP32.
     */
    public function store(StoreSensorReadingRequest $request): JsonResponse
    {
        /** @var Device $device */
        $device = $request->attributes->get('authenticated_device');
        $validated = $request->validated();

        if ($validated['device_id'] !== $device->device_code) {
            $message = 'device_id pada payload tidak cocok dengan API token perangkat.';

            $device->forceFill([
                'last_tx_status' => 'error',
                'last_tx_error' => $message,
            ])->save();

            return response()->json([
                'status' => 'error',
                'message' => $message,
            ], 403);
        }

        $numericFields = ['voltage', 'current', 'power', 'temperature', 'lux'];
        foreach ($numericFields as $field) {
            $value = (float) $validated[$field];
            if (! is_finite($value)) {
                return response()->json([
                    'status' => 'error',
                    'message' => "Nilai {$field} tidak valid (NaN/Infinite).",
                ], 422);
            }
        }

        $recordedAt = isset($validated['recorded_at'])
            ? Carbon::parse($validated['recorded_at'])
            : now();

        try {
            $reading = DB::transaction(function () use ($device, $validated, $recordedAt): SensorReading {
                $reading = SensorReading::query()->create([
                    'device_id' => $device->id,
                    'voltage' => round((float) $validated['voltage'], 3),
                    'current' => round((float) $validated['current'], 3),
                    'power' => round((float) $validated['power'], 4),
                    'temperature' => round((float) $validated['temperature'], 2),
                    'lux' => round((float) $validated['lux'], 2),
                    'recorded_at' => $recordedAt,
                    'created_at' => now(),
                ]);

                $device->forceFill([
                    'last_seen_at' => now(),
                    'last_tx_status' => 'success',
                    'last_tx_error' => null,
                ])->save();

                return $reading;
            });

            return response()->json([
                'status' => 'success',
                'message' => 'Data sensor berhasil disimpan.',
                'data' => [
                    'reading_id' => $reading->id,
                    'device_id' => $device->device_code,
                    'voltage' => $reading->voltage,
                    'current' => $reading->current,
                    'power' => $reading->power,
                    'temperature' => $reading->temperature,
                    'lux' => $reading->lux,
                    'recorded_at' => $reading->recorded_at->toIso8601String(),
                ],
            ], 201);
        } catch (Throwable $e) {
            Log::error('Gagal menyimpan data sensor ESP32', [
                'device_code' => $device->device_code,
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'status' => 'error',
                'message' => 'Terjadi kesalahan server saat menyimpan data sensor.',
            ], 500);
        }
    }
}
