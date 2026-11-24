<?php

namespace App\Http\Controllers;

use App\Models\Device;
use App\Models\SystemSetting;
use App\Services\DeviceStatusService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class DeviceController extends Controller
{
    public function __construct(
        protected DeviceStatusService $deviceStatusService
    ) {}

    /**
     * Display all registered ESP32 devices, connection statuses, transmission logs, and sensor details.
     */
    public function index(): View
    {
        $devices = Device::query()
            ->withCount('readings')
            ->with('latestReading')
            ->orderBy('id')
            ->get()
            ->map(function (Device $device): array {
                return [
                    'model' => $device,
                    'status' => $this->deviceStatusService->describe($device),
                ];
            });

        return view('devices.index', [
            'siteName' => SystemSetting::getValue('site_name', 'Solar Panel Monitoring IoT'),
            'devices' => $devices,
            'defaultOfflineThreshold' => (int) SystemSetting::getValue('default_offline_threshold', 30),
            'apiEndpointUrl' => url('/api/sensor-data'),
        ]);
    }

    /**
     * Register a new ESP32 monitoring device and display its generated API token once.
     */
    public function store(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'device_code' => ['required', 'string', 'max:50', 'alpha_dash', 'unique:devices,device_code'],
            'name' => ['required', 'string', 'max:100'],
            'location' => ['nullable', 'string', 'max:150'],
            'description' => ['nullable', 'string', 'max:1000'],
            'send_interval_sec' => ['required', 'integer', 'min:1', 'max:3600'],
            'offline_timeout_sec' => ['required', 'integer', 'min:5', 'max:86400'],
        ], [
            'device_code.required' => 'ID perangkat (device_code) wajib diisi.',
            'device_code.alpha_dash' => 'ID perangkat hanya boleh berisi huruf, angka, garis bawah (_), atau tanda hubung (-).',
            'device_code.unique' => 'ID perangkat sudah terdaftar.',
            'name.required' => 'Nama perangkat wajib diisi.',
        ]);

        $device = new Device([
            'device_code' => strtoupper(trim($validated['device_code'])),
            'name' => trim($validated['name']),
            'location' => $validated['location'] ? trim($validated['location']) : null,
            'description' => $validated['description'] ? trim($validated['description']) : null,
            'sensors_info' => [
                'voltage_current_power' => 'INA219 (I2C SDA GPIO21, SCL GPIO22)',
                'temperature' => 'DS18B20 Waterproof (1-Wire GPIO4 + 4.7k Ohm)',
                'light_intensity' => 'BH1750 (I2C SDA GPIO21, SCL GPIO22)',
                'local_display' => 'LCD I2C 16x2 (SDA GPIO21, SCL GPIO22)',
            ],
            'send_interval_sec' => (int) $validated['send_interval_sec'],
            'offline_timeout_sec' => (int) $validated['offline_timeout_sec'],
            'last_tx_status' => 'never',
            'is_active' => true,
            'created_by' => Auth::id(),
        ]);

        $plainToken = $device->issueNewApiToken();
        $device->save();

        return redirect()
            ->route('devices.index')
            ->with('status', "Perangkat {$device->device_code} berhasil ditambahkan.")
            ->with('generated_token', [
                'device_code' => $device->device_code,
                'token' => $plainToken,
            ]);
    }

    /**
     * Update device metadata, intervals, and active status.
     */
    public function update(Request $request, Device $device): RedirectResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:100'],
            'location' => ['nullable', 'string', 'max:150'],
            'description' => ['nullable', 'string', 'max:1000'],
            'send_interval_sec' => ['required', 'integer', 'min:1', 'max:3600'],
            'offline_timeout_sec' => ['required', 'integer', 'min:5', 'max:86400'],
            'is_active' => ['required', Rule::in(['0', '1', 0, 1, true, false])],
        ]);

        $device->update([
            'name' => trim($validated['name']),
            'location' => $validated['location'] ? trim($validated['location']) : null,
            'description' => $validated['description'] ? trim($validated['description']) : null,
            'send_interval_sec' => (int) $validated['send_interval_sec'],
            'offline_timeout_sec' => (int) $validated['offline_timeout_sec'],
            'is_active' => filter_var($validated['is_active'], FILTER_VALIDATE_BOOLEAN),
        ]);

        return redirect()
            ->route('devices.index')
            ->with('status', "Konfigurasi perangkat {$device->device_code} berhasil diperbarui.");
    }

    /**
     * Rotate and reissue a fresh API token for the given device.
     */
    public function rotateToken(Device $device): RedirectResponse
    {
        $plainToken = $device->issueNewApiToken();
        $device->save();

        return redirect()
            ->route('devices.index')
            ->with('status', "API Token baru untuk {$device->device_code} berhasil dibuat. Segera perbarui token pada firmware ESP32.")
            ->with('generated_token', [
                'device_code' => $device->device_code,
                'token' => $plainToken,
            ]);
    }
}
