<?php

namespace Tests\Feature;

use App\Models\Device;
use App\Models\SensorReading;
use App\Models\SystemSetting;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class DatabaseStructureTest extends TestCase
{
    use RefreshDatabase;

    public function test_core_tables_and_columns_exist(): void
    {
        $this->assertTrue(Schema::hasColumns('users', [
            'id', 'name', 'username', 'email', 'password', 'role', 'is_active', 'last_login_at',
        ]));

        $this->assertTrue(Schema::hasColumns('devices', [
            'id', 'device_code', 'name', 'api_token_hash', 'sensors_info',
            'send_interval_sec', 'offline_timeout_sec', 'last_seen_at', 'last_tx_status', 'is_active',
        ]));

        $this->assertTrue(Schema::hasColumns('sensor_readings', [
            'id', 'device_id', 'voltage', 'current', 'power', 'temperature', 'lux', 'recorded_at',
        ]));

        $this->assertTrue(Schema::hasColumns('system_settings', [
            'id', 'setting_key', 'setting_value', 'value_type', 'updated_by',
        ]));
    }

    public function test_seeder_creates_initial_admin_device_and_settings_without_dummy_readings(): void
    {
        $this->seed(DatabaseSeeder::class);

        $admin = User::query()->where('username', 'admin')->first();
        $this->assertNotNull($admin);
        $this->assertSame('admin', $admin->role);

        $device = Device::query()->where('device_code', 'ESP32_SOLAR_01')->first();
        $this->assertNotNull($device);
        $this->assertTrue($device->matchesApiToken('slr_dev_esp32_solar_01_token_2026'));
        $this->assertFalse($device->isOnline());

        $this->assertSame(0, SensorReading::query()->count());
        $this->assertSame(5, SystemSetting::getValue('dashboard_refresh_interval'));
        $this->assertTrue(SystemSetting::getValue('public_dashboard_enabled'));
    }

    public function test_device_online_status_and_reading_relationship(): void
    {
        $this->seed(DatabaseSeeder::class);

        $device = Device::query()->where('device_code', 'ESP32_SOLAR_01')->firstOrFail();

        $device->forceFill([
            'last_seen_at' => now()->subSeconds(10),
            'last_tx_status' => 'success',
        ])->save();

        $this->assertTrue($device->fresh()->isOnline());

        $reading = SensorReading::query()->create([
            'device_id' => $device->id,
            'voltage' => 18.500,
            'current' => 250.000,
            'power' => 4.6250,
            'temperature' => 32.50,
            'lux' => 12500.00,
            'recorded_at' => now(),
            'created_at' => now(),
        ]);

        $this->assertSame($device->id, $reading->device->id);
        $this->assertSame(1, $device->readings()->count());
        $this->assertSame(18.5, $device->latestReading->voltage);
    }
}
