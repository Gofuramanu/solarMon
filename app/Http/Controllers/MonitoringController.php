<?php

namespace App\Http\Controllers;

use App\Models\Device;
use App\Models\SystemSetting;
use App\Services\TelemetryQueryService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class MonitoringController extends Controller
{
    public function __construct(
        protected TelemetryQueryService $telemetryQueryService
    ) {}

    /**
     * Display the live auto-updating 5-chart Real-Time Monitoring page.
     */
    public function realtime(Request $request): View
    {
        return $this->renderMonitoringView($request, 'realtime');
    }

    /**
     * Display the Historical Charts page with period and specific date filters.
     */
    public function history(Request $request): View
    {
        return $this->renderMonitoringView($request, 'history');
    }

    /**
     * Return time-series JSON for Chart.js updates without reloading the page.
     */
    public function chartData(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'device' => ['nullable', 'string', 'max:50'],
            'period' => ['nullable', Rule::in(['1h', '6h', '12h', '24h', 'date'])],
            'date' => ['nullable', 'date_format:Y-m-d'],
        ]);

        $devices = Device::query()->where('is_active', true)->orderBy('id')->get();
        $selectedDevice = ! empty($validated['device'])
            ? $devices->firstWhere('device_code', $validated['device'])
            : $devices->first();

        if (! $selectedDevice) {
            $selectedDevice = $devices->first();
        }

        $period = $validated['period'] ?? TelemetryQueryService::PERIOD_1H;
        $date = $validated['date'] ?? now()->format('Y-m-d');

        $payload = $this->telemetryQueryService->buildChartPayload($selectedDevice, $period, $date);
        $payload['refresh_interval_sec'] = max(2, (int) SystemSetting::getValue('dashboard_refresh_interval', 5));

        return response()->json($payload);
    }

    /**
     * Shared renderer for realtime and historical chart views.
     */
    protected function renderMonitoringView(Request $request, string $mode): View
    {
        $devices = Device::query()->where('is_active', true)->orderBy('id')->get();
        $deviceCode = $request->query('device');
        $selectedDevice = $deviceCode ? $devices->firstWhere('device_code', $deviceCode) : $devices->first();

        if (! $selectedDevice) {
            $selectedDevice = $devices->first();
        }

        $defaultPeriod = $mode === 'history'
            ? TelemetryQueryService::PERIOD_24H
            : TelemetryQueryService::PERIOD_1H;

        $period = $request->query('period', $defaultPeriod);
        if (! in_array($period, ['1h', '6h', '12h', '24h', 'date'], true)) {
            $period = $defaultPeriod;
        }

        $date = $request->query('date', now()->format('Y-m-d'));
        $initialChartData = $this->telemetryQueryService->buildChartPayload($selectedDevice, $period, $date);
        $refreshIntervalSec = max(2, (int) SystemSetting::getValue('dashboard_refresh_interval', 5));

        $viewName = $mode === 'history' ? 'monitoring.history' : 'monitoring.realtime';

        return view($viewName, [
            'siteName' => SystemSetting::getValue('site_name', 'Solar Panel Monitoring IoT'),
            'devices' => $devices,
            'selectedDevice' => $selectedDevice,
            'initialChartData' => $initialChartData,
            'activePeriod' => $initialChartData['period'],
            'selectedDate' => $initialChartData['date'],
            'refreshIntervalSec' => $refreshIntervalSec,
            'mode' => $mode,
        ]);
    }
}
