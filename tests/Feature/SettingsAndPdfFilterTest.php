<?php

namespace Tests\Feature;

use App\Models\Device;
use App\Models\SensorReading;
use App\Models\SystemSetting;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class SettingsAndPdfFilterTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
        $this->admin = User::query()->where('username', 'admin')->firstOrFail();
    }

    public function test_admin_can_update_system_settings_and_account_credentials(): void
    {
        $this->actingAs($this->admin)
            ->get(route('settings.index'))
            ->assertStatus(200)
            ->assertSee('Konfigurasi Operasional Monitoring');

        $this->actingAs($this->admin)
            ->put(route('settings.system'), [
                'site_name' => 'UNY Solar Telemetry',
                'dashboard_refresh_interval' => 10,
                'default_offline_threshold' => 45,
                'energy_max_gap_seconds' => 90,
                'public_dashboard_enabled' => '0',
            ])->assertRedirect(route('settings.index'));

        $this->assertSame('UNY Solar Telemetry', SystemSetting::getValue('site_name'));
        $this->assertSame(10, SystemSetting::getValue('dashboard_refresh_interval'));
        $this->assertSame(45, SystemSetting::getValue('default_offline_threshold'));
        $this->assertSame(90, SystemSetting::getValue('energy_max_gap_seconds'));
        $this->assertFalse(SystemSetting::getValue('public_dashboard_enabled'));

        $this->actingAs($this->admin)
            ->put(route('settings.account'), [
                'name' => 'Ilyas Admin',
                'username' => 'ilyas_admin',
                'email' => 'ilyas@uny.test',
                'current_password' => 'admin12345',
                'new_password' => 'newpass12345',
                'new_password_confirmation' => 'newpass12345',
            ])->assertRedirect(route('settings.index'));

        $updated = $this->admin->fresh();
        $this->assertSame('Ilyas Admin', $updated->name);
        $this->assertSame('ilyas_admin', $updated->username);
        $this->assertTrue(Hash::check('newpass12345', $updated->password));
    }

    public function test_admin_can_clear_sensor_readings_before_hardware_testing(): void
    {
        $device = Device::query()->where('device_code', 'ESP32_SOLAR_01')->firstOrFail();
        $device->forceFill(['last_seen_at' => now(), 'last_tx_status' => 'success'])->save();

        SensorReading::query()->create([
            'device_id' => $device->id,
            'voltage' => 18.5,
            'current' => 250.0,
            'power' => 4.625,
            'temperature' => 32.5,
            'lux' => 12500,
            'recorded_at' => now(),
            'created_at' => now(),
        ]);

        $this->actingAs($this->admin)
            ->post(route('settings.clear-readings'))
            ->assertRedirect(route('settings.index'));

        $this->assertSame(0, SensorReading::query()->count());
        $this->assertSame('never', $device->fresh()->last_tx_status);
        $this->assertNull($device->fresh()->last_seen_at);
    }

    public function test_pdf_export_summary_respects_search_filter(): void
    {
        $device = Device::query()->where('device_code', 'ESP32_SOLAR_01')->firstOrFail();

        SensorReading::query()->create([
            'device_id' => $device->id,
            'voltage' => 18.50,
            'current' => 250.0,
            'power' => 4.625,
            'temperature' => 32.5,
            'lux' => 12500,
            'recorded_at' => now()->subMinutes(10),
            'created_at' => now(),
        ]);

        SensorReading::query()->create([
            'device_id' => $device->id,
            'voltage' => 18.75,
            'current' => 265.0,
            'power' => 4.9688,
            'temperature' => 33.1,
            'lux' => 14200,
            'recorded_at' => now(),
            'created_at' => now(),
        ]);

        $response = $this->get(route('logs.export.pdf', ['search' => '18.75']));
        $response->assertStatus(200);
        $this->assertStringStartsWith('%PDF-', $response->getContent());
    }
}
