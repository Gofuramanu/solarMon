<?php

namespace Database\Seeders;

use App\Models\Device;
use App\Models\SystemSetting;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class DatabaseSeeder extends Seeder
{
    /**
     * Seed the application's database with initial Admin, ESP32 device, and settings.
     * Sensor readings are intentionally left empty so all measurements come from actual ESP32/API data.
     */
    public function run(): void
    {
        $admin = User::query()->updateOrCreate(
            ['username' => 'admin'],
            [
                'name' => 'Administrator',
                'email' => 'admin@solarmonitoring.test',
                'password' => Hash::make('admin12345'),
                'role' => 'admin',
                'is_active' => true,
                'email_verified_at' => now(),
            ]
        );

        // Token default awal untuk pengujian lokal; sebaiknya di-rotate via menu Manajemen Perangkat di produksi.
        $defaultPlainToken = env('ESP32_DEFAULT_TOKEN', 'slr_dev_esp32_solar_01_token_2026');

        Device::query()->updateOrCreate(
            ['device_code' => 'ESP32_SOLAR_01'],
            [
                'name' => 'Solar Panel Unit 01',
                'location' => 'Lab Energi Surya - Atap Gedung',
                'description' => 'ESP32 DevKit V1 dengan sensor INA219, DS18B20 Waterproof, BH1750, dan LCD I2C 16x2.',
                'api_token_hash' => hash('sha256', $defaultPlainToken),
                'api_token_prefix' => substr($defaultPlainToken, 0, 12),
                'sensors_info' => [
                    'voltage_current_power' => 'INA219 (I2C SDA GPIO21, SCL GPIO22)',
                    'temperature' => 'DS18B20 Waterproof (1-Wire GPIO4 + 4.7k Ohm)',
                    'light_intensity' => 'BH1750 (I2C SDA GPIO21, SCL GPIO22)',
                    'local_display' => 'LCD I2C 16x2 (SDA GPIO21, SCL GPIO22)',
                ],
                'send_interval_sec' => 5,
                'offline_timeout_sec' => 30,
                'last_tx_status' => 'never',
                'is_active' => true,
                'created_by' => $admin->id,
            ]
        );

        $defaultSettings = [
            [
                'setting_key' => 'site_name',
                'setting_value' => 'Solar Panel Monitoring IoT',
                'value_type' => 'string',
                'description' => 'Nama aplikasi yang ditampilkan di header dan laporan PDF',
            ],
            [
                'setting_key' => 'dashboard_refresh_interval',
                'setting_value' => '5',
                'value_type' => 'integer',
                'description' => 'Interval pembaruan otomatis dashboard dan grafik real-time (detik)',
            ],
            [
                'setting_key' => 'default_offline_threshold',
                'setting_value' => '30',
                'value_type' => 'integer',
                'description' => 'Batas waktu tanpa kiriman data sebelum perangkat dianggap Offline (detik)',
            ],
            [
                'setting_key' => 'energy_max_gap_seconds',
                'setting_value' => '60',
                'value_type' => 'integer',
                'description' => 'Batas jeda maksimum antar pembacaan untuk integrasi energi Wh/kWh (detik)',
            ],
            [
                'setting_key' => 'public_dashboard_enabled',
                'setting_value' => 'true',
                'value_type' => 'boolean',
                'description' => 'Izinkan akses publik read-only untuk halaman monitoring',
            ],
        ];

        foreach ($defaultSettings as $item) {
            SystemSetting::query()->updateOrCreate(
                ['setting_key' => $item['setting_key']],
                array_merge($item, ['updated_by' => $admin->id])
            );
        }
    }
}
