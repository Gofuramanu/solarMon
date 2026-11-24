<?php

namespace App\Http\Controllers;

use App\Models\Device;
use App\Models\SystemSetting;
use App\Services\EnergyAnalysisService;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\View\View;

class AnalysisController extends Controller
{
    public function __construct(
        protected EnergyAnalysisService $energyAnalysisService
    ) {}

    /**
     * Display statistical averages, peaks, lows, and time-integrated energy (Wh / kWh).
     */
    public function index(Request $request): View
    {
        $devices = Device::query()->where('is_active', true)->orderBy('id')->get();
        $deviceCode = $request->query('device');
        $selectedDevice = $deviceCode ? $devices->firstWhere('device_code', $deviceCode) : $devices->first();

        if (! $selectedDevice) {
            $selectedDevice = $devices->first();
        }

        $range = $request->query('range', '24h');
        $dateFromInput = (string) $request->query('date_from', '');
        $dateToInput = (string) $request->query('date_to', '');

        $now = now();
        $from = null;
        $to = null;
        $rangeLabel = '24 Jam Terakhir';

        if ($range === 'custom'
            && preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateFromInput)
            && preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateToInput)
        ) {
            $from = Carbon::createFromFormat('Y-m-d', $dateFromInput)->startOfDay();
            $to = Carbon::createFromFormat('Y-m-d', $dateToInput)->endOfDay();
            if ($from->gt($to)) {
                [$from, $to] = [$to->copy()->startOfDay(), $from->copy()->endOfDay()];
            }
            $rangeLabel = $from->format('d M Y') . ' s/d ' . $to->format('d M Y');
        } elseif ($range === 'all') {
            $rangeLabel = 'Seluruh Data Tersimpan';
        } else {
            $hours = match ($range) {
                '1h' => 1,
                '6h' => 6,
                '12h' => 12,
                '7d' => 168,
                default => 24,
            };
            $range = match ($hours) {
                1 => '1h',
                6 => '6h',
                12 => '12h',
                168 => '7d',
                default => '24h',
            };
            $from = $now->copy()->subHours($hours);
            $to = $now->copy();
            $rangeLabel = $hours === 168 ? '7 Hari Terakhir' : "{$hours} Jam Terakhir";
        }

        $analysis = $this->energyAnalysisService->analyze($selectedDevice, $from, $to);

        return view('analysis.index', [
            'siteName' => SystemSetting::getValue('site_name', 'Solar Panel Monitoring IoT'),
            'devices' => $devices,
            'selectedDevice' => $selectedDevice,
            'range' => $range,
            'rangeLabel' => $rangeLabel,
            'dateFrom' => $dateFromInput ?: $now->format('Y-m-d'),
            'dateTo' => $dateToInput ?: $now->format('Y-m-d'),
            'analysis' => $analysis,
        ]);
    }
}
