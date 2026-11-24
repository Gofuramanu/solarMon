<?php

namespace Tests\Feature;

use App\Models\Device;
use App\Models\SensorReading;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MonitoringChartsTest extends TestCase
{
    use RefreshDatabase;

    private Device $device;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
        $this->device = Device::query()->where('device_code', 'ESP32_SOLAR_01')->firstOrFail();
    }

    public function test_realtime_and_history_chart_pages_render_all_five_charts_and_filters(): void
    {
        foreach (['monitoring.realtime', 'monitoring.history'] as $routeName) {
            $this->get(route($routeName))
                ->assertStatus(200)
                ->assertSee('Grafik Tegangan terhadap Waktu')
                ->assertSee('Grafik Arus Listrik terhadap Waktu')
                ->assertSee('Grafik Daya Listrik terhadap Waktu')
                ->assertSee('Grafik Temperatur Panel terhadap Waktu')
                ->assertSee('Grafik Intensitas Cahaya terhadap Waktu')
                ->assertSee('1 Jam Terakhir')
                ->assertSee('6 Jam Terakhir')
                ->assertSee('12 Jam Terakhir')
                ->assertSee('24 Jam Terakhir')
                ->assertSee('Pilih Tanggal:');
        }
    }

    public function test_chart_data_endpoint_filters_by_relative_period_chronologically(): void
    {
        $now = now();

        // 30 minutes ago (inside 1h, 6h, 12h, 24h)
        SensorReading::query()->create([
            'device_id' => $this->device->id,
            'voltage' => 18.2,
            'current' => 240.0,
            'power' => 4.368,
            'temperature' => 31.5,
            'lux' => 12000,
            'recorded_at' => $now->copy()->subMinutes(30),
            'created_at' => $now,
        ]);

        // 3 hours ago (excluded from 1h, included in 6h/12h/24h)
        SensorReading::query()->create([
            'device_id' => $this->device->id,
            'voltage' => 17.5,
            'current' => 200.0,
            'power' => 3.5,
            'temperature' => 30.0,
            'lux' => 9500,
            'recorded_at' => $now->copy()->subHours(3),
            'created_at' => $now,
        ]);

        // 10 hours ago (included in 12h/24h only)
        SensorReading::query()->create([
            'device_id' => $this->device->id,
            'voltage' => 16.8,
            'current' => 150.0,
            'power' => 2.52,
            'temperature' => 28.0,
            'lux' => 5000,
            'recorded_at' => $now->copy()->subHours(10),
            'created_at' => $now,
        ]);

        $this->getJson(route('monitoring.chart-data', ['device' => 'ESP32_SOLAR_01', 'period' => '1h']))
            ->assertStatus(200)
            ->assertJsonPath('total_points', 1)
            ->assertJsonPath('series.voltage.0', 18.2);

        $this->getJson(route('monitoring.chart-data', ['device' => 'ESP32_SOLAR_01', 'period' => '6h']))
            ->assertStatus(200)
            ->assertJsonPath('total_points', 2)
            ->assertJsonPath('series.voltage.0', 17.5)
            ->assertJsonPath('series.voltage.1', 18.2);

        $this->getJson(route('monitoring.chart-data', ['device' => 'ESP32_SOLAR_01', 'period' => '12h']))
            ->assertStatus(200)
            ->assertJsonPath('total_points', 3)
            ->assertJsonPath('series.voltage.0', 16.8);
    }

    public function test_chart_data_endpoint_filters_by_specific_date_and_validates_date_format(): void
    {
        $targetDate = now()->subDays(2)->format('Y-m-d');
        $otherDate = now()->subDays(1)->format('Y-m-d');

        SensorReading::query()->create([
            'device_id' => $this->device->id,
            'voltage' => 19.1,
            'current' => 300.0,
            'power' => 5.73,
            'temperature' => 33.4,
            'lux' => 18500,
            'recorded_at' => $targetDate . ' 11:30:00',
            'created_at' => now(),
        ]);

        SensorReading::query()->create([
            'device_id' => $this->device->id,
            'voltage' => 15.0,
            'current' => 100.0,
            'power' => 1.5,
            'temperature' => 27.0,
            'lux' => 3200,
            'recorded_at' => $otherDate . ' 09:00:00',
            'created_at' => now(),
        ]);

        $this->getJson(route('monitoring.chart-data', [
            'device' => 'ESP32_SOLAR_01',
            'period' => 'date',
            'date' => $targetDate,
        ]))
            ->assertStatus(200)
            ->assertJsonPath('total_points', 1)
            ->assertJsonPath('series.voltage.0', 19.1)
            ->assertJsonPath('series.lux.0', 18500);

        $this->getJson(route('monitoring.chart-data', [
            'device' => 'ESP32_SOLAR_01',
            'period' => 'date',
            'date' => 'invalid-date',
        ]))->assertStatus(422);
    }

    public function test_chart_data_downsamples_large_datasets_safely(): void
    {
        $start = now()->subHours(5);
        $rows = [];
        for ($i = 0; $i < 300; $i++) {
            $rows[] = [
                'device_id' => $this->device->id,
                'voltage' => 18.0 + ($i % 5) * 0.1,
                'current' => 220.0,
                'power' => 3.96,
                'temperature' => 31.0,
                'lux' => 11000,
                'recorded_at' => $start->copy()->addSeconds($i * 30),
                'created_at' => now(),
            ];
        }
        SensorReading::query()->insert($rows);

        $response = $this->getJson(route('monitoring.chart-data', [
            'device' => 'ESP32_SOLAR_01',
            'period' => '6h',
        ]))->assertStatus(200);

        $this->assertSame(300, $response->json('total_points'));
        $this->assertLessThanOrEqual(240, $response->json('sampled_points'));
    }
}
