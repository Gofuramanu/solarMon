<?php

namespace Tests\Feature;

use App\Models\Device;
use App\Models\SensorReading;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DeviceManagementAndIntegrationTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
        $this->admin = User::query()->where('username', 'admin')->firstOrFail();
    }

    public function test_admin_can_view_device_management_page_with_sensor_and_status_info(): void
    {
        $device = Device::query()->where('device_code', 'ESP32_SOLAR_01')->firstOrFail();
        $device->forceFill([
            'last_seen_at' => now()->subSeconds(5),
            'last_tx_status' => 'success',
        ])->save();

        $this->actingAs($this->admin)
            ->get(route('devices.index'))
            ->assertStatus(200)
            ->assertSee('ESP32_SOLAR_01')
            ->assertSee('Online')
            ->assertSee('Berhasil')
            ->assertSee('INA219')
            ->assertSee('DS18B20')
            ->assertSee('BH1750');
    }

    public function test_admin_can_register_new_device_and_send_data_to_mysql_with_new_token(): void
    {
        $response = $this->actingAs($this->admin)
            ->post(route('devices.store'), [
                'device_code' => 'ESP32_SOLAR_02',
                'name' => 'Solar Unit Rooftop B',
                'location' => 'Gedung B',
                'description' => 'Unit pengujian panel surya kedua',
                'send_interval_sec' => 10,
                'offline_timeout_sec' => 45,
            ]);

        $response->assertRedirect(route('devices.index'));
        $generated = session('generated_token');
        $this->assertNotNull($generated);
        $this->assertSame('ESP32_SOLAR_02', $generated['device_code']);

        $this->withToken($generated['token'])
            ->postJson('/api/sensor-data', [
                'device_id' => 'ESP32_SOLAR_02',
                'voltage' => 19.20,
                'current' => 310.50,
                'power' => 5.9616,
                'temperature' => 34.1,
                'lux' => 24500,
            ])->assertStatus(201);

        $device = Device::query()->where('device_code', 'ESP32_SOLAR_02')->firstOrFail();
        $this->assertTrue($device->isOnline());
        $this->assertSame(1, $device->readings()->count());
        $this->assertSame(19.2, $device->latestReading->voltage);
    }

    public function test_admin_can_update_device_configuration_and_rotate_token(): void
    {
        $device = Device::query()->where('device_code', 'ESP32_SOLAR_01')->firstOrFail();

        $this->actingAs($this->admin)
            ->put(route('devices.update', $device), [
                'name' => 'Solar Panel Unit 01 Updated',
                'location' => 'Lapangan Uji Surya',
                'description' => 'Panel 50Wp',
                'send_interval_sec' => 5,
                'offline_timeout_sec' => 60,
                'is_active' => '1',
            ])->assertRedirect(route('devices.index'));

        $this->assertSame('Solar Panel Unit 01 Updated', $device->fresh()->name);
        $this->assertSame(60, $device->fresh()->offline_timeout_sec);

        $rotateResponse = $this->actingAs($this->admin)
            ->post(route('devices.rotate-token', $device));

        $rotateResponse->assertRedirect(route('devices.index'));
        $newToken = session('generated_token')['token'];

        // Old dev token must no longer authenticate
        $this->withToken('slr_dev_esp32_solar_01_token_2026')
            ->postJson('/api/sensor-data', [
                'device_id' => 'ESP32_SOLAR_01',
                'voltage' => 18.0,
                'current' => 200.0,
                'power' => 3.6,
                'temperature' => 30.0,
                'lux' => 10000,
            ])->assertStatus(401);

        // New rotated token must succeed and persist to database
        $this->withToken($newToken)
            ->postJson('/api/sensor-data', [
                'device_id' => 'ESP32_SOLAR_01',
                'voltage' => 18.1,
                'current' => 210.0,
                'power' => 3.801,
                'temperature' => 30.5,
                'lux' => 11000,
            ])->assertStatus(201);

        $this->assertSame(1, SensorReading::query()->where('device_id', $device->id)->count());
    }
}
