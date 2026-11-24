<?php

namespace App\Http\Controllers;

use App\Models\Device;
use App\Models\SensorReading;
use App\Models\SystemSetting;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class SettingController extends Controller
{
    /**
     * Display system operational settings and Admin account settings.
     */
    public function index(): View
    {
        return view('settings.index', [
            'siteName' => SystemSetting::getValue('site_name', 'Solar Panel Monitoring IoT'),
            'refreshInterval' => (int) SystemSetting::getValue('dashboard_refresh_interval', 5),
            'offlineThreshold' => (int) SystemSetting::getValue('default_offline_threshold', 30),
            'energyMaxGap' => (int) SystemSetting::getValue('energy_max_gap_seconds', 60),
            'publicDashboardEnabled' => (bool) SystemSetting::getValue('public_dashboard_enabled', true),
            'totalReadingsCount' => SensorReading::query()->count(),
            'user' => Auth::user(),
        ]);
    }

    /**
     * Update global monitoring and energy calculation settings.
     */
    public function updateSystem(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'site_name' => ['required', 'string', 'max:100'],
            'dashboard_refresh_interval' => ['required', 'integer', 'min:2', 'max:300'],
            'default_offline_threshold' => ['required', 'integer', 'min:10', 'max:3600'],
            'energy_max_gap_seconds' => ['required', 'integer', 'min:10', 'max:3600'],
            'public_dashboard_enabled' => ['required', Rule::in(['0', '1'])],
        ]);

        $userId = Auth::id();

        SystemSetting::setValue('site_name', trim($validated['site_name']), $userId);
        SystemSetting::setValue('dashboard_refresh_interval', (int) $validated['dashboard_refresh_interval'], $userId);
        SystemSetting::setValue('default_offline_threshold', (int) $validated['default_offline_threshold'], $userId);
        SystemSetting::setValue('energy_max_gap_seconds', (int) $validated['energy_max_gap_seconds'], $userId);
        SystemSetting::setValue('public_dashboard_enabled', $validated['public_dashboard_enabled'] === '1', $userId);

        return redirect()
            ->route('settings.index')
            ->with('status', 'Pengaturan sistem berhasil disimpan.');
    }

    /**
     * Update Admin profile and optionally change password.
     */
    public function updateAccount(Request $request): RedirectResponse
    {
        /** @var User $user */
        $user = Auth::user();

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:100'],
            'username' => ['required', 'string', 'max:50', 'alpha_dash', Rule::unique('users', 'username')->ignore($user->id)],
            'email' => ['required', 'email', 'max:150', Rule::unique('users', 'email')->ignore($user->id)],
            'current_password' => ['nullable', 'string'],
            'new_password' => ['nullable', 'string', 'min:8', 'confirmed'],
        ], [
            'new_password.min' => 'Password baru minimal 8 karakter.',
            'new_password.confirmed' => 'Konfirmasi password baru tidak cocok.',
        ]);

        if (! empty($validated['new_password'])) {
            if (empty($validated['current_password']) || ! Hash::check($validated['current_password'], $user->password)) {
                return back()
                    ->withErrors(['current_password' => 'Password saat ini tidak sesuai.'])
                    ->withInput($request->only('name', 'username', 'email'));
            }

            $user->password = $validated['new_password'];
        }

        $user->name = trim($validated['name']);
        $user->username = trim($validated['username']);
        $user->email = trim($validated['email']);
        $user->save();

        return redirect()
            ->route('settings.index')
            ->with('status', 'Profil akun Admin berhasil diperbarui.');
    }

    /**
     * Clear all stored sensor readings (useful to reset simulated test rows before live hardware demo).
     */
    public function clearReadings(): RedirectResponse
    {
        DB::transaction(function (): void {
            SensorReading::query()->delete();
            Device::query()->update([
                'last_seen_at' => null,
                'last_tx_status' => 'never',
                'last_tx_error' => null,
            ]);
        });

        return redirect()
            ->route('settings.index')
            ->with('status', 'Seluruh riwayat pembacaan sensor berhasil dikosongkan untuk pengujian ulang.');
    }
}
