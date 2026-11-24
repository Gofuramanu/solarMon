<?php

namespace App\Http\Controllers;

use App\Models\Device;
use App\Models\SensorReading;
use App\Models\SystemSetting;
use App\Services\DeviceStatusService;
use App\Services\EnergyAnalysisService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\View\View;

class DashboardController extends Controller
{
    public function __construct(
        protected DeviceStatusService $deviceStatusService,
        protected EnergyAnalysisService $energyAnalysisService
    ) {}

    /**
     * Display the main Solar Energy Monitoring dashboard.
     */
    public function index(Request $request): View
    {
        $payload = $this->buildDashboardPayload($request->query('device'));

        return view('dashboard.index', $payload);
    }

    /**
     * Return latest device status, sensor reading, and summary widgets as JSON for periodic polling.
     */
    public function latest(Request $request): JsonResponse
    {
        $payload = $this->buildDashboardPayload($request->query('device'));

        /** @var Device|null $device */
        $device = $payload['selectedDevice'];
        /** @var SensorReading|null $reading */
        $reading = $payload['latestReading'];

        return response()->json([
            'device' => $device ? [
                'device_code' => $device->device_code,
                'name' => $device->name,
                'location' => $device->location,
                'is_online' => $payload['isOnline'],
                'last_tx_status' => $device->last_tx_status,
                'offline_timeout_sec' => $payload['offlineTimeoutSec'],
                'last_seen_iso' => $device->last_seen_at?->toIso8601String(),
                'last_seen_formatted' => $device->last_seen_at?->format('d M Y, H:i:s'),
                'last_seen_human' => $device->last_seen_at?->diffForHumans(),
            ] : null,
            'reading' => $reading ? [
                'id' => $reading->id,
                'voltage' => $reading->voltage,
                'current' => $reading->current,
                'power' => $reading->power,
                'temperature' => $reading->temperature,
                'lux' => $reading->lux,
                'recorded_at_iso' => $reading->recorded_at->toIso8601String(),
                'recorded_at_formatted' => $reading->recorded_at->format('d M Y, H:i:s'),
                'recorded_at_clock' => $reading->recorded_at->format('H:i:s'),
                'recorded_at_human' => $reading->recorded_at->diffForHumans(),
            ] : null,
            'recent_readings' => $payload['recentReadings']->map(fn (SensorReading $row): array => [
                'id' => $row->id,
                'voltage' => $row->voltage,
                'current' => $row->current,
                'power' => $row->power,
                'temperature' => $row->temperature,
                'lux' => $row->lux,
                'clock' => $row->recorded_at->format('H:i:s'),
            ])->values(),
            'mini_chart' => $payload['miniChart'],
            'today_energy' => [
                'energy_wh' => $payload['todayAnalysis']['energy_wh'],
                'energy_kwh' => $payload['todayAnalysis']['energy_kwh'],
                'integrated_seconds' => $payload['todayAnalysis']['integrated_seconds'],
                'skipped_gaps' => $payload['todayAnalysis']['skipped_gaps'],
                'valid_intervals' => $payload['todayAnalysis']['valid_intervals'],
                'peak_power' => $payload['todayAnalysis']['metrics']['power']['max'],
            ],
            'total_readings' => $payload['totalReadings'],
            'refresh_interval_sec' => $payload['refreshIntervalSec'],
            'server_time' => now()->format('d M Y, H:i:s'),
        ]);
    }

    /**
     * Assemble real-time dashboard state exclusively from persisted database records.
     *
     * @return array<string, mixed>
     */
    protected function buildDashboardPayload(?string $deviceCode = null): array
    {
        $devices = Device::query()
            ->where('is_active', true)
            ->orderBy('id')
            ->get();

        $selectedDevice = $deviceCode
            ? $devices->firstWhere('device_code', $deviceCode)
            : $devices->first();

        if (! $selectedDevice) {
            $selectedDevice = $devices->first();
        }

        $defaultOfflineThreshold = (int) SystemSetting::getValue('default_offline_threshold', 30);
        $refreshIntervalSec = max(2, (int) SystemSetting::getValue('dashboard_refresh_interval', 5));

        $latestReading = null;
        $recentReadings = new Collection();
        $miniChart = ['labels' => [], 'power' => [], 'voltage' => []];
        $todayAnalysis = $this->energyAnalysisService->analyze(null);
        $totalReadings = 0;
        $isOnline = false;
        $offlineTimeoutSec = $defaultOfflineThreshold;

        if ($selectedDevice) {
            $status = $this->deviceStatusService->describe($selectedDevice);
            $offlineTimeoutSec = $status['offline_timeout_sec'];
            $isOnline = $status['is_online'];

            $recentDesc = SensorReading::query()
                ->where('device_id', $selectedDevice->id)
                ->orderByDesc('recorded_at')
                ->orderByDesc('id')
                ->limit(8)
                ->get();

            $latestReading = $recentDesc->first();
            $recentReadings = $recentDesc->take(4);

            $chronological = $recentDesc->reverse()->values();
            $miniChart = [
                'labels' => $chronological->map(fn (SensorReading $r) => $r->recorded_at->format('H:i:s'))->all(),
                'power' => $chronological->map(fn (SensorReading $r) => (float) $r->power)->all(),
                'voltage' => $chronological->map(fn (SensorReading $r) => (float) $r->voltage)->all(),
            ];

            $totalReadings = SensorReading::query()
                ->where('device_id', $selectedDevice->id)
                ->count();

            $todayAnalysis = $this->energyAnalysisService->analyze(
                $selectedDevice,
                now()->startOfDay(),
                now()->endOfDay()
            );
        }

        return [
            'siteName' => SystemSetting::getValue('site_name', 'Solar Panel Monitoring IoT'),
            'devices' => $devices,
            'selectedDevice' => $selectedDevice,
            'latestReading' => $latestReading,
            'recentReadings' => $recentReadings,
            'miniChart' => $miniChart,
            'todayAnalysis' => $todayAnalysis,
            'totalReadings' => $totalReadings,
            'isOnline' => $isOnline,
            'offlineTimeoutSec' => $offlineTimeoutSec,
            'refreshIntervalSec' => $refreshIntervalSec,
        ];
    }
}
