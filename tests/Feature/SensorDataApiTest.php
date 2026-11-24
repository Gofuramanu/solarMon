<?php

namespace Tests\Feature;

use App\Models\Device;
use App\Models\SensorReading;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SensorDataApiTest extends TestCase
{
    use RefreshDatabase;

    private string $validToken = 'slr_dev_esp32_solar_01_token_2026';

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
    }

    public function test_api_health_endpoint_returns_ok(): void
    {
        $this->getJson('/api/health')
            ->assertStatus(200)
            ->assertJsonPath('status', 'ok');
    }

    public function test_sensor_data_requires_api_token(): void
    {
        $this->postJson('/api/sensor-data', [
            'device_id' => 'ESP32_SOLAR_01',
            'voltage' => 18.50,
            'current' => 250.00,
            'power' => 4.625,
            'temperature' => 32.5,
            'lux' => 12500,
        ])->assertStatus(401)
            ->assertJsonPath('status', 'error');

        $this->assertSame(0, SensorReading::query()->count());
    }

    public function test_sensor_data_rejects_invalid_api_token(): void
    {
        $this->withToken('invalid-token-123')
            ->postJson('/api/sensor-data', [
                'device_id' => 'ESP32_SOLAR_01',
                'voltage' => 18.50,
                'current' => 250.00,
                'power' => 4.625,
                'temperature' => 32.5,
                'lux' => 12500,
            ])->assertStatus(401);

        $this->assertSame(0, SensorReading::query()->count());
    }

    public function test_sensor_data_stores_valid_payload_with_bearer_token_and_updates_device(): void
    {
        $response = $this->withToken($this->validToken)
            ->postJson('/api/sensor-data', [
                'device_id' => 'ESP32_SOLAR_01',
                'voltage' => 18.50,
                'current' => 250.00,
                'power' => 4.625,
                'temperature' => 32.5,
                'lux' => 12500,
            ]);

        $response->assertStatus(201)
            ->assertJsonPath('status', 'success')
            ->assertJsonPath('data.device_id', 'ESP32_SOLAR_01')
            ->assertJsonPath('data.voltage', 18.5)
            ->assertJsonPath('data.power', 4.625);

        $this->assertSame(1, SensorReading::query()->count());

        $device = Device::query()->where('device_code', 'ESP32_SOLAR_01')->firstOrFail();
        $this->assertSame('success', $device->last_tx_status);
        $this->assertNull($device->last_tx_error);
        $this->assertNotNull($device->last_seen_at);
        $this->assertTrue($device->isOnline());
    }

    public function test_sensor_data_accepts_x_api_key_header(): void
    {
        $this->withHeaders(['X-API-Key' => $this->validToken])
            ->postJson('/api/sensor-data', [
                'device_id' => 'ESP32_SOLAR_01',
                'voltage' => 17.8,
                'current' => 210.5,
                'power' => 3.7469,
                'temperature' => 31.2,
                'lux' => 9800,
            ])->assertStatus(201);

        $this->assertSame(1, SensorReading::query()->count());
    }

    public function test_sensor_data_rejects_mismatched_device_id(): void
    {
        $this->withToken($this->validToken)
            ->postJson('/api/sensor-data', [
                'device_id' => 'ESP32_OTHER_99',
                'voltage' => 18.50,
                'current' => 250.00,
                'power' => 4.625,
                'temperature' => 32.5,
                'lux' => 12500,
            ])->assertStatus(403)
            ->assertJsonPath('status', 'error');

        $this->assertSame(0, SensorReading::query()->count());
    }

    public function test_sensor_data_rejects_invalid_or_disconnected_sensor_values_as_json(): void
    {
        // Note: using plain post() without Accept: application/json to verify ESP32 clients always get JSON 422
        $response = $this->withToken($this->validToken)
            ->post('/api/sensor-data', [
                'device_id' => 'ESP32_SOLAR_01',
                'voltage' => -5.0,
                'current' => 250.00,
                'power' => 4.625,
                'temperature' => -127.0,
                'lux' => 12500,
            ]);

        $response->assertStatus(422)
            ->assertJsonPath('status', 'error')
            ->assertJsonStructure(['status', 'message', 'errors' => ['voltage', 'temperature']]);

        $this->assertSame(0, SensorReading::query()->count());

        $device = Device::query()->where('device_code', 'ESP32_SOLAR_01')->firstOrFail();
        $this->assertSame('error', $device->last_tx_status);
        $this->assertNotNull($device->last_tx_error);
    }

    public function test_inactive_device_is_rejected_with_403(): void
    {
        Device::query()->where('device_code', 'ESP32_SOLAR_01')->update(['is_active' => false]);

        $this->withToken($this->validToken)
            ->postJson('/api/sensor-data', [
                'device_id' => 'ESP32_SOLAR_01',
                'voltage' => 18.50,
                'current' => 250.00,
                'power' => 4.625,
                'temperature' => 32.5,
                'lux' => 12500,
            ])->assertStatus(403);

        $this->assertSame(0, SensorReading::query()->count());
    }
}
