<?php

namespace Tests\Feature;

use App\Models\Device;
use App\Models\SensorReading;
use App\Services\EnergyAnalysisService;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class LogsAndAnalysisTest extends TestCase
{
    use RefreshDatabase;

    private Device $device;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
        $this->device = Device::query()->where('device_code', 'ESP32_SOLAR_01')->firstOrFail();
    }

    public function test_logs_page_supports_search_date_filter_and_sorting(): void
    {
        $today = now()->format('Y-m-d');
        $yesterday = now()->subDay()->format('Y-m-d');

        SensorReading::query()->create([
            'device_id' => $this->device->id,
            'voltage' => 17.0,
            'current' => 100.0,
            'power' => 1.7,
            'temperature' => 29.0,
            'lux' => 4500,
            'recorded_at' => $yesterday . ' 10:00:00',
            'created_at' => now(),
        ]);

        SensorReading::query()->create([
            'device_id' => $this->device->id,
            'voltage' => 19.5,
            'current' => 400.0,
            'power' => 7.8,
            'temperature' => 36.2,
            'lux' => 32000,
            'recorded_at' => $today . ' 12:00:00',
            'created_at' => now(),
        ]);

        // Filter by today's date only
        $this->get(route('logs.index', ['date_from' => $today, 'date_to' => $today]))
            ->assertStatus(200)
            ->assertSee('19.50 V')
            ->assertDontSee('17.00 V');

        // Search by specific lux value
        $this->get(route('logs.index', ['search' => '32000']))
            ->assertStatus(200)
            ->assertSee('7.800 W')
            ->assertDontSee('1.700 W');

        // Sort ascending by voltage
        $this->get(route('logs.index', ['sort' => 'voltage', 'direction' => 'asc']))
            ->assertStatus(200)
            ->assertSeeInOrder(['17.00 V', '19.50 V']);
    }

    public function test_logs_export_csv_and_pdf_return_valid_downloads(): void
    {
        SensorReading::query()->create([
            'device_id' => $this->device->id,
            'voltage' => 18.5,
            'current' => 250.0,
            'power' => 4.625,
            'temperature' => 32.5,
            'lux' => 12500,
            'recorded_at' => now(),
            'created_at' => now(),
        ]);

        $csvResponse = $this->get(route('logs.export.csv'));
        $csvResponse->assertStatus(200);
        $this->assertStringContainsString('text/csv', (string) $csvResponse->headers->get('Content-Type'));
        $csvContent = $csvResponse->streamedContent();
        $this->assertStringContainsString('ESP32_SOLAR_01', $csvContent);
        $this->assertStringContainsString('18.500', $csvContent);
        $this->assertStringContainsString('4.6250', $csvContent);

        $pdfResponse = $this->get(route('logs.export.pdf'));
        $pdfResponse->assertStatus(200);
        $this->assertStringContainsString('application/pdf', (string) $pdfResponse->headers->get('Content-Type'));
        $this->assertStringStartsWith('%PDF-', $pdfResponse->getContent());
    }

    public function test_energy_analysis_integrates_power_over_time_and_skips_offline_gaps(): void
    {
        $base = now()->subMinutes(10);

        // Segment 1: t=0s (10W) -> t=30s (20W) => avg 15W * (30/3600)h = 0.125 Wh
        SensorReading::query()->create([
            'device_id' => $this->device->id,
            'voltage' => 20.0,
            'current' => 500.0,
            'power' => 10.0,
            'temperature' => 30.0,
            'lux' => 10000,
            'recorded_at' => $base->copy(),
            'created_at' => $base,
        ]);

        SensorReading::query()->create([
            'device_id' => $this->device->id,
            'voltage' => 20.0,
            'current' => 1000.0,
            'power' => 20.0,
            'temperature' => 35.0,
            'lux' => 20000,
            'recorded_at' => $base->copy()->addSeconds(30),
            'created_at' => $base,
        ]);

        // Gap of 120s (> 60s threshold) must be skipped, then Segment 2: t=150s (30W) -> t=210s (30W) => 30W * (60/3600)h = 0.500 Wh
        SensorReading::query()->create([
            'device_id' => $this->device->id,
            'voltage' => 20.0,
            'current' => 1500.0,
            'power' => 30.0,
            'temperature' => 40.0,
            'lux' => 30000,
            'recorded_at' => $base->copy()->addSeconds(150),
            'created_at' => $base,
        ]);

        SensorReading::query()->create([
            'device_id' => $this->device->id,
            'voltage' => 20.0,
            'current' => 1500.0,
            'power' => 30.0,
            'temperature' => 45.0,
            'lux' => 40000,
            'recorded_at' => $base->copy()->addSeconds(210),
            'created_at' => $base,
        ]);

        $service = app(EnergyAnalysisService::class);
        $result = $service->analyze($this->device, maxGapSeconds: 60);

        $this->assertSame(4, $result['total_records']);
        $this->assertSame(2, $result['valid_intervals']);
        $this->assertSame(1, $result['skipped_gaps']);
        $this->assertSame(90, $result['integrated_seconds']);
        // Total expected = 0.125 + 0.500 = 0.625 Wh (0.000625 kWh)
        $this->assertEqualsWithDelta(0.625, $result['energy_wh'], 0.0001);
        $this->assertEqualsWithDelta(0.000625, $result['energy_kwh'], 0.000001);

        // Aggregates check
        $this->assertSame(22.5, $result['metrics']['power']['avg']);
        $this->assertSame(30.0, $result['metrics']['power']['max']);
        $this->assertSame(10.0, $result['metrics']['power']['min']);

        // Analysis page renders these calculations
        $this->get(route('analysis.index', ['device' => 'ESP32_SOLAR_01', 'range' => '24h']))
            ->assertStatus(200)
            ->assertSee('0.6250')
            ->assertSee('0.00063');
    }
}
