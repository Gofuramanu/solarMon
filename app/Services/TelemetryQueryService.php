<?php

namespace App\Services;

use App\Models\Device;
use App\Models\SensorReading;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

class TelemetryQueryService
{
    public const PERIOD_1H = '1h';
    public const PERIOD_6H = '6h';
    public const PERIOD_12H = '12h';
    public const PERIOD_24H = '24h';
    public const PERIOD_DATE = 'date';

    public function __construct(
        protected DeviceStatusService $deviceStatusService
    ) {}

    /**
     * Resolve start/end Carbon bounds and human-readable label for a chart period.
     *
     * @return array{period: string, start: Carbon, end: Carbon, label: string, date: string}
     */
    public function resolvePeriodWindow(string $period, ?string $date = null): array
    {
        $now = now();

        if ($period === self::PERIOD_DATE && $date !== null && preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
            $parsedDate = Carbon::createFromFormat('Y-m-d', $date)->startOfDay();

            return [
                'period' => self::PERIOD_DATE,
                'start' => $parsedDate->copy()->startOfDay(),
                'end' => $parsedDate->copy()->endOfDay(),
                'label' => 'Tanggal ' . $parsedDate->format('d M Y'),
                'date' => $parsedDate->format('Y-m-d'),
            ];
        }

        $hours = match ($period) {
            self::PERIOD_6H => 6,
            self::PERIOD_12H => 12,
            self::PERIOD_24H => 24,
            default => 1,
        };

        $normalizedPeriod = match ($hours) {
            6 => self::PERIOD_6H,
            12 => self::PERIOD_12H,
            24 => self::PERIOD_24H,
            default => self::PERIOD_1H,
        };

        return [
            'period' => $normalizedPeriod,
            'start' => $now->copy()->subHours($hours),
            'end' => $now->copy(),
            'label' => "{$hours} Jam Terakhir",
            'date' => $date && preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) ? $date : $now->format('Y-m-d'),
        ];
    }

    /**
     * Fetch chronological Chart.js series data for a device within a period window.
     *
     * @return array<string, mixed>
     */
    public function buildChartPayload(?Device $device, string $period, ?string $date = null, int $maxPoints = 240): array
    {
        $window = $this->resolvePeriodWindow($period, $date);

        if (! $device) {
            return [
                'device' => null,
                'period' => $window['period'],
                'date' => $window['date'],
                'period_label' => $window['label'],
                'total_points' => 0,
                'sampled_points' => 0,
                'labels' => [],
                'timestamps' => [],
                'series' => [
                    'voltage' => [],
                    'current' => [],
                    'power' => [],
                    'temperature' => [],
                    'lux' => [],
                ],
                'latest' => null,
            ];
        }

        $readings = SensorReading::query()
            ->where('device_id', $device->id)
            ->whereBetween('recorded_at', [$window['start'], $window['end']])
            ->orderBy('recorded_at')
            ->orderBy('id')
            ->get(['id', 'voltage', 'current', 'power', 'temperature', 'lux', 'recorded_at']);

        $totalPoints = $readings->count();
        $sampled = $this->downsampleReadings($readings, $maxPoints);

        $labelFormat = in_array($window['period'], [self::PERIOD_1H, self::PERIOD_6H], true)
            ? 'H:i:s'
            : 'd/m H:i';

        $labels = [];
        $timestamps = [];
        $voltage = [];
        $current = [];
        $power = [];
        $temperature = [];
        $lux = [];

        foreach ($sampled as $row) {
            $labels[] = $row->recorded_at->format($labelFormat);
            $timestamps[] = $row->recorded_at->format('d M Y, H:i:s');
            $voltage[] = (float) $row->voltage;
            $current[] = (float) $row->current;
            $power[] = (float) $row->power;
            $temperature[] = (float) $row->temperature;
            $lux[] = (float) $row->lux;
        }

        $status = $this->deviceStatusService->describe($device);
        $latestRow = $readings->last();

        return [
            'device' => [
                'device_code' => $device->device_code,
                'name' => $device->name,
                'is_online' => $status['is_online'],
                'connection_label' => $status['connection_label'],
                'last_seen_formatted' => $status['last_seen_formatted'],
            ],
            'period' => $window['period'],
            'date' => $window['date'],
            'period_label' => $window['label'],
            'total_points' => $totalPoints,
            'sampled_points' => $sampled->count(),
            'labels' => $labels,
            'timestamps' => $timestamps,
            'series' => [
                'voltage' => $voltage,
                'current' => $current,
                'power' => $power,
                'temperature' => $temperature,
                'lux' => $lux,
            ],
            'latest' => $latestRow ? [
                'voltage' => (float) $latestRow->voltage,
                'current' => (float) $latestRow->current,
                'power' => (float) $latestRow->power,
                'temperature' => (float) $latestRow->temperature,
                'lux' => (float) $latestRow->lux,
                'recorded_at_formatted' => $latestRow->recorded_at->format('d M Y, H:i:s'),
            ] : null,
        ];
    }

    /**
     * Evenly downsample large collections while always preserving the first and last measurement.
     *
     * @param  Collection<int, SensorReading>  $readings
     * @return Collection<int, SensorReading>
     */
    protected function downsampleReadings(Collection $readings, int $maxPoints): Collection
    {
        $count = $readings->count();
        if ($count <= $maxPoints || $maxPoints < 2) {
            return $readings;
        }

        $result = new Collection();
        $step = ($count - 1) / ($maxPoints - 1);

        for ($i = 0; $i < $maxPoints; $i++) {
            $index = (int) round($i * $step);
            $result->push($readings->get($index));
        }

        return $result->unique('id')->values();
    }
}
