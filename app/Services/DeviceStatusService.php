<?php

namespace App\Services;

use App\Models\Device;
use App\Models\SystemSetting;

class DeviceStatusService
{
    /**
     * Resolve effective offline timeout in seconds for a device.
     */
    public function resolveOfflineTimeout(Device $device): int
    {
        $defaultTimeout = (int) SystemSetting::getValue('default_offline_threshold', 30);

        return $device->offline_timeout_sec > 0
            ? $device->offline_timeout_sec
            : max(10, $defaultTimeout);
    }

    /**
     * Compute connection and transmission status metadata for UI/API display.
     *
     * @return array<string, mixed>
     */
    public function describe(Device $device): array
    {
        $timeout = $this->resolveOfflineTimeout($device);
        $isOnline = $device->isOnline($timeout);

        $txStatus = match ($device->last_tx_status) {
            'success' => [
                'code' => 'success',
                'label' => 'Berhasil',
                'badge_class' => 'bg-emerald-50 text-emerald-700 border-emerald-200',
            ],
            'error' => [
                'code' => 'error',
                'label' => 'Gagal Validasi / Kirim',
                'badge_class' => 'bg-rose-50 text-rose-700 border-rose-200',
            ],
            default => [
                'code' => 'never',
                'label' => 'Belum Pernah Mengirim',
                'badge_class' => 'bg-slate-100 text-slate-600 border-slate-200',
            ],
        };

        return [
            'is_online' => $isOnline,
            'connection_label' => $isOnline ? 'Online' : 'Offline',
            'offline_timeout_sec' => $timeout,
            'last_seen_formatted' => $device->last_seen_at?->format('d M Y, H:i:s') ?? 'Belum ada data',
            'last_seen_human' => $device->last_seen_at?->diffForHumans() ?? 'Belum pernah terhubung',
            'tx_status' => $txStatus,
        ];
    }
}
