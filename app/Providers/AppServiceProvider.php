<?php

namespace App\Providers;

use App\Models\Device;
use App\Models\SystemSetting;
use App\Services\DeviceStatusService;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Facades\View;
use Illuminate\Support\ServiceProvider;
use Throwable;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        if (app()->environment('production') || ! empty($_ENV['VERCEL'] ?? $_SERVER['VERCEL'] ?? getenv('VERCEL'))) {
            URL::forceScheme('https');
        }

        View::composer('layouts.app', function (\Illuminate\View\View $view): void {
            $summary = [
                'device_code' => 'ESP32_SOLAR_01',
                'is_online' => false,
                'online_count' => 0,
                'total_count' => 0,
                'send_interval_sec' => 5,
                'site_name' => 'SolarMonitor',
            ];

            try {
                if (Schema::hasTable('devices')) {
                    $devices = Device::query()->where('is_active', true)->orderBy('id')->get();
                    $statusService = app(DeviceStatusService::class);
                    $onlineCount = $devices->filter(fn (Device $d) => $statusService->describe($d)['is_online'])->count();
                    $primary = $devices->first();

                    $summary['total_count'] = $devices->count();
                    $summary['online_count'] = $onlineCount;
                    $summary['is_online'] = $onlineCount > 0;
                    if ($primary) {
                        $summary['device_code'] = $primary->device_code;
                        $summary['send_interval_sec'] = max(1, (int) $primary->send_interval_sec);
                    }
                }

                if (Schema::hasTable('system_settings')) {
                    $summary['site_name'] = (string) SystemSetting::getValue('site_name', 'SolarMonitor');
                }
            } catch (Throwable) {
                // Fallback safely during artisan package discovery or unmigrated boot
            }

            $view->with('shellTelemetry', $summary);
        });
    }
}
