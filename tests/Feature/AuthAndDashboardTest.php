<?php

namespace Tests\Feature;

use App\Models\Device;
use App\Models\SensorReading;
use App\Models\SystemSetting;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AuthAndDashboardTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
    }

    public function test_login_page_is_accessible(): void
    {
        $response = $this->get(route('login'));

        $response->assertStatus(200);
        $response->assertSee('Masuk sebagai Admin');
    }

    public function test_admin_can_login_with_username_and_logout(): void
    {
        $response = $this->post(route('login.attempt'), [
            'login' => 'admin',
            'password' => 'admin12345',
        ]);

        $response->assertRedirect(route('dashboard'));
        $this->assertAuthenticated();
        $this->assertNotNull(User::query()->where('username', 'admin')->first()->last_login_at);

        $logoutResponse = $this->post(route('logout'));
        $logoutResponse->assertRedirect(route('login'));
        $this->assertGuest();
    }

    public function test_admin_can_login_with_email(): void
    {
        $response = $this->post(route('login.attempt'), [
            'login' => 'admin@solarmonitoring.test',
            'password' => 'admin12345',
        ]);

        $response->assertRedirect(route('dashboard'));
        $this->assertAuthenticated();
    }

    public function test_invalid_credentials_fail_validation(): void
    {
        $response = $this->from(route('login'))->post(route('login.attempt'), [
            'login' => 'admin',
            'password' => 'wrong-password',
        ]);

        $response->assertRedirect(route('login'));
        $response->assertSessionHasErrors('login');
        $this->assertGuest();
    }

    public function test_inactive_admin_cannot_login(): void
    {
        User::query()->where('username', 'admin')->update(['is_active' => false]);

        $response = $this->from(route('login'))->post(route('login.attempt'), [
            'login' => 'admin',
            'password' => 'admin12345',
        ]);

        $response->assertRedirect(route('login'));
        $response->assertSessionHasErrors('login');
        $this->assertGuest();
    }

    public function test_public_dashboard_access_respects_system_setting(): void
    {
        // Default seeded: public_dashboard_enabled = true
        $this->get(route('dashboard'))
            ->assertStatus(200)
            ->assertSee('Menunggu Data Aktual dari ESP32');

        // When switched to private, guests must redirect to login
        SystemSetting::setValue('public_dashboard_enabled', false);

        $this->get(route('dashboard'))
            ->assertRedirect(route('login'));

        $admin = User::query()->where('username', 'admin')->firstOrFail();
        $this->actingAs($admin)
            ->get(route('dashboard'))
            ->assertStatus(200);
    }

    public function test_admin_only_pages_require_authentication(): void
    {
        $this->get(route('devices.index'))->assertRedirect(route('login'));
        $this->get(route('settings.index'))->assertRedirect(route('login'));

        $admin = User::query()->where('username', 'admin')->firstOrFail();
        $this->actingAs($admin)->get(route('devices.index'))->assertStatus(200);
        $this->actingAs($admin)->get(route('settings.index'))->assertStatus(200);
    }

    public function test_dashboard_and_latest_endpoint_return_actual_database_readings(): void
    {
        $device = Device::query()->where('device_code', 'ESP32_SOLAR_01')->firstOrFail();
        $now = now();

        $device->forceFill([
            'last_seen_at' => $now,
            'last_tx_status' => 'success',
        ])->save();

        SensorReading::query()->create([
            'device_id' => $device->id,
            'voltage' => 18.500,
            'current' => 250.000,
            'power' => 4.6250,
            'temperature' => 32.50,
            'lux' => 12500.00,
            'recorded_at' => $now,
            'created_at' => $now,
        ]);

        $this->get(route('dashboard'))
            ->assertStatus(200)
            ->assertSee('ESP32 Online')
            ->assertSee('18.50')
            ->assertSee('250.00')
            ->assertSee('4.625')
            ->assertSee('32.5')
            ->assertSee('12.500');

        $this->getJson(route('dashboard.latest', ['device' => 'ESP32_SOLAR_01']))
            ->assertStatus(200)
            ->assertJsonPath('device.device_code', 'ESP32_SOLAR_01')
            ->assertJsonPath('device.is_online', true)
            ->assertJsonPath('reading.voltage', 18.5)
            ->assertJsonPath('reading.power', 4.625)
            ->assertJsonPath('total_readings', 1);
    }
}
