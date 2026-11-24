<?php

namespace App\Services;

use App\Models\Device;
use App\Models\SensorReading;
use App\Models\SystemSetting;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

class EnergyAnalysisService
{
    /**
     * Build a reusable base query for logs and analysis filters.
     */
    public function buildFilteredQuery(
        ?Device $device = null,
        ?Carbon $from = null,
        ?Carbon $to = null,
        ?string $search = null
    ): Builder {
        $query = SensorReading::query()->with('device');

        if ($device) {
            $query->where('device_id', $device->id);
        }

        if ($from) {
            $query->where('recorded_at', '>=', $from);
        }

        if ($to) {
            $query->where('recorded_at', '<=', $to);
        }

        if ($search !== null && trim($search) !== '') {
            $term = trim($search);
            $query->where(function (Builder $q) use ($term): void {
                if (ctype_digit($term)) {
                    $q->orWhere('id', (int) $term);
                }

                $q->orWhereHas('device', function (Builder $dq) use ($term): void {
                    $dq->where('device_code', 'like', "%{$term}%")
                        ->orWhere('name', 'like', "%{$term}%");
                });

                $q->orWhereRaw('CAST(voltage AS CHAR) LIKE ?', ["%{$term}%"])
                    ->orWhereRaw('CAST(current AS CHAR) LIKE ?', ["%{$term}%"])
                    ->orWhereRaw('CAST(power AS CHAR) LIKE ?', ["%{$term}%"])
                    ->orWhereRaw('CAST(temperature AS CHAR) LIKE ?', ["%{$term}%"])
                    ->orWhereRaw('CAST(lux AS CHAR) LIKE ?', ["%{$term}%"])
                    ->orWhereRaw('CAST(recorded_at AS CHAR) LIKE ?', ["%{$term}%"]);
            });
        }

        return $query;
    }

    /**
     * Compute AVG/MAX/MIN aggregates and trapezoidal time-integrated energy (Wh / kWh).
     *
     * @return array<string, mixed>
     */
    public function analyze(
        ?Device $device = null,
        ?Carbon $from = null,
        ?Carbon $to = null,
        ?int $maxGapSeconds = null,
        ?string $search = null
    ): array {
        $gapLimit = $maxGapSeconds ?? max(5, (int) SystemSetting::getValue('energy_max_gap_seconds', 60));

        $baseQuery = $this->buildFilteredQuery($device, $from, $to, $search);

        $aggregates = (clone $baseQuery)
            ->select([
                DB::raw('COUNT(*) as total_count'),
                DB::raw('AVG(voltage) as avg_voltage'),
                DB::raw('MAX(voltage) as max_voltage'),
                DB::raw('MIN(voltage) as min_voltage'),
                DB::raw('AVG(current) as avg_current'),
                DB::raw('MAX(current) as max_current'),
                DB::raw('MIN(current) as min_current'),
                DB::raw('AVG(power) as avg_power'),
                DB::raw('MAX(power) as max_power'),
                DB::raw('MIN(power) as min_power'),
                DB::raw('AVG(temperature) as avg_temperature'),
                DB::raw('MAX(temperature) as max_temperature'),
                DB::raw('MIN(temperature) as min_temperature'),
                DB::raw('AVG(lux) as avg_lux'),
                DB::raw('MAX(lux) as max_lux'),
                DB::raw('MIN(lux) as min_lux'),
            ])
            ->first();

        $totalCount = (int) ($aggregates->total_count ?? 0);

        // Numerical trapezoidal integration of power (W) over delta time (hours) per device
        $energyWh = 0.0;
        $integratedSeconds = 0;
        $validIntervals = 0;
        $skippedGaps = 0;

        if ($totalCount >= 2) {
            $prevByDevice = [];

            $cursor = (clone $baseQuery)
                ->select(['id', 'device_id', 'power', 'recorded_at'])
                ->orderBy('device_id')
                ->orderBy('recorded_at')
                ->orderBy('id')
                ->cursor();

            foreach ($cursor as $row) {
                $devId = (int) $row->device_id;
                $ts = $row->recorded_at->getTimestamp();
                $powerW = (float) $row->power;

                if (isset($prevByDevice[$devId])) {
                    $prev = $prevByDevice[$devId];
                    $dt = $ts - $prev['ts'];

                    if ($dt > 0 && $dt <= $gapLimit) {
                        $avgSegmentPower = ($prev['power'] + $powerW) / 2.0;
                        $energyWh += $avgSegmentPower * ($dt / 3600.0);
                        $integratedSeconds += $dt;
                        $validIntervals++;
                    } elseif ($dt > $gapLimit) {
                        $skippedGaps++;
                    }
                }

                $prevByDevice[$devId] = [
                    'ts' => $ts,
                    'power' => $powerW,
                ];
            }
        }

        $formatMetric = static function (string $key, int $decimals) use ($aggregates, $totalCount): array {
            if ($totalCount === 0 || ! $aggregates) {
                return ['avg' => null, 'max' => null, 'min' => null];
            }

            return [
                'avg' => round((float) $aggregates->{"avg_{$key}"}, $decimals),
                'max' => round((float) $aggregates->{"max_{$key}"}, $decimals),
                'min' => round((float) $aggregates->{"min_{$key}"}, $decimals),
            ];
        };

        return [
            'total_records' => $totalCount,
            'energy_wh' => round($energyWh, 4),
            'energy_kwh' => round($energyWh / 1000.0, 6),
            'integrated_seconds' => $integratedSeconds,
            'valid_intervals' => $validIntervals,
            'skipped_gaps' => $skippedGaps,
            'max_gap_seconds' => $gapLimit,
            'metrics' => [
                'voltage' => $formatMetric('voltage', 3),
                'current' => $formatMetric('current', 2),
                'power' => $formatMetric('power', 4),
                'temperature' => $formatMetric('temperature', 2),
                'lux' => $formatMetric('lux', 2),
            ],
        ];
    }
}
