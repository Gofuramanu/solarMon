@extends('layouts.app')

@section('title', 'Manajemen Perangkat | ' . $siteName)
@section('page_title', 'Manajemen Perangkat ESP32')
@section('page_subtitle', 'Status koneksi ESP32, riwayat pengiriman HTTP REST API, pinout sensor I2C/1-Wire, dan pengelolaan API token.')

@section('page_actions')
    <button type="button"
            onclick="document.getElementById('add-device-panel').classList.toggle('hidden')"
            class="shrink-0 inline-flex items-center justify-center gap-2 min-h-[44px] sm:min-h-[40px] px-4 py-2 rounded-xl hero-cobalt-card text-white text-xs font-semibold shadow-sm hover:opacity-95 transition">
        <span>+ Tambah Perangkat ESP32</span>
    </button>
@endsection

@section('content')
    @if (session('status'))
        <div class="mb-5 rounded-2xl bg-emerald-50 border border-emerald-200 px-5 py-3.5 text-sm text-emerald-800 flex items-center justify-between gap-4">
            <span>{{ session('status') }}</span>
        </div>
    @endif

    @if ($errors->any())
        <div class="mb-5 rounded-2xl bg-red-50 border border-red-200 px-5 py-3.5 text-sm text-[#BA1A1A]">
            <ul class="list-disc list-inside space-y-1">
                @foreach ($errors->all() as $error)
                    <li>{{ $error }}</li>
                @endforeach
            </ul>
        </div>
    @endif

    <!-- Newly Generated / Rotated API Token Banner (Shown Once) -->
    @if (session('generated_token'))
        @php $tokenData = session('generated_token'); @endphp
        <div class="mb-6 rounded-3xl hero-cobalt-card text-white p-5 sm:p-6 shadow-[0_12px_28px_-6px_rgba(30,64,175,0.3)]">
            <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-3">
                <div>
                    <h2 class="text-base font-bold text-white">Simpan API Token Baru ({{ $tokenData['device_code'] }})</h2>
                    <p class="text-xs text-cobalt-100 mt-1">
                        Demi keamanan, server hanya menyimpan hash SHA-256. Token penuh hanya ditampilkan sekali ini untuk dimasukkan ke firmware ESP32.
                    </p>
                </div>
                <button type="button"
                        onclick="copyTextToClipboard(@js($tokenData['token']), this)"
                        class="shrink-0 min-h-[44px] sm:min-h-[40px] px-4 py-2 rounded-xl bg-white hover:bg-cobalt-50 text-cobalt-900 text-xs font-bold transition">
                    Salin Token
                </button>
            </div>
            <div class="mt-3 rounded-2xl bg-slate-950/80 border border-white/15 px-4 py-3 font-mono text-xs sm:text-sm text-emerald-300 break-all">
                {{ $tokenData['token'] }}
            </div>
        </div>
    @endif

    <!-- Top 4-Card Hardware Architecture Strip (Matches FigCraft Desktop 6452) -->
    <div class="grid grid-cols-1 sm:grid-cols-2 xl:grid-cols-4 gap-4 mb-6">
        <div class="instrument-card rounded-3xl p-4 sm:p-5 flex items-center gap-3.5">
            <div class="w-10 h-10 rounded-full bg-cobalt-50 text-cobalt-800 flex items-center justify-center font-bold text-xs shrink-0" aria-hidden="true">MCU</div>
            <div class="min-w-0">
                <div class="text-[11px] font-semibold uppercase tracking-wider text-slateink">Mikrokontroler</div>
                <div class="text-sm font-bold text-slate-900 truncate">ESP32 DevKit V1</div>
                <div class="text-xs text-slateink truncate">30 Pin Wi-Fi SoC</div>
            </div>
        </div>
        <div class="instrument-card rounded-3xl p-4 sm:p-5 flex items-center gap-3.5">
            <div class="w-10 h-10 rounded-full bg-cobalt-50 text-cobalt-800 flex items-center justify-center font-bold text-xs shrink-0" aria-hidden="true">I2C</div>
            <div class="min-w-0">
                <div class="text-[11px] font-semibold uppercase tracking-wider text-slateink">Bus I2C Aktif</div>
                <div class="text-sm font-bold text-slate-900 truncate">SDA 21 / SCL 22</div>
                <div class="text-xs text-slateink truncate">INA219, BH1750, LCD</div>
            </div>
        </div>
        <div class="instrument-card rounded-3xl p-4 sm:p-5 flex items-center gap-3.5">
            <div class="w-10 h-10 rounded-full bg-cobalt-50 text-cobalt-800 flex items-center justify-center font-bold text-xs shrink-0" aria-hidden="true">1W</div>
            <div class="min-w-0">
                <div class="text-[11px] font-semibold uppercase tracking-wider text-slateink">Bus 1-Wire Suhu</div>
                <div class="text-sm font-bold text-slate-900 truncate">GPIO 4 (DS18B20)</div>
                <div class="text-xs text-slateink truncate">Pull-up 4.7k Ohm 3V3</div>
            </div>
        </div>
        <div class="instrument-card rounded-3xl p-4 sm:p-5 flex items-center gap-3.5">
            <div class="w-10 h-10 rounded-full bg-cobalt-50 text-cobalt-800 flex items-center justify-center font-bold text-xs shrink-0" aria-hidden="true">API</div>
            <div class="min-w-0">
                <div class="text-[11px] font-semibold uppercase tracking-wider text-slateink">Ingestion Endpoint</div>
                <div class="text-sm font-bold text-cobalt-800 truncate">POST /api/sensor-data</div>
                <div class="text-xs text-slateink truncate">Bearer / X-API-Key</div>
            </div>
        </div>
    </div>

    <!-- Endpoint Notice Card -->
    <div class="instrument-card rounded-3xl p-5 mb-6 flex flex-col lg:flex-row lg:items-center lg:justify-between gap-4">
        <div class="min-w-0">
            <div class="text-xs font-semibold uppercase tracking-wider text-slateink">Alamat Lengkap REST API Server</div>
            <div class="mt-1.5 flex flex-wrap items-center gap-2">
                <span class="px-2.5 py-0.5 rounded-full bg-cobalt-50 text-cobalt-800 font-mono text-xs font-bold border border-cobalt-100">POST</span>
                <code class="text-sm font-mono text-slate-900 break-all">{{ $apiEndpointUrl }}</code>
            </div>
            <p class="mt-1 text-xs text-slateink">
                Status <strong>Online/Offline</strong> dihitung otomatis berdasarkan selisih waktu sekarang dengan waktu terakhir data valid diterima server.
            </p>
        </div>
    </div>

    <!-- Add Device Form (Collapsible) -->
    <div id="add-device-panel" class="{{ $errors->has('device_code') ? '' : 'hidden' }} instrument-card rounded-3xl p-6 mb-6">
        <h2 class="text-base font-bold text-slate-900 mb-4">Daftarkan Perangkat ESP32 Baru</h2>
        <form method="POST" action="{{ route('devices.store') }}" class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-4">
            @csrf
            <div>
                <label for="new-device-code" class="block text-xs font-semibold text-slateink mb-1">ID Perangkat (device_code)</label>
                <input id="new-device-code" type="text" name="device_code" value="{{ old('device_code') }}" required
                       placeholder="ESP32_SOLAR_02"
                       class="w-full min-h-[44px] sm:min-h-[40px] rounded-xl border border-slate-200 bg-canvas px-3.5 py-2 text-sm font-mono uppercase text-slate-900 focus:border-cobalt-800">
            </div>
            <div>
                <label for="new-device-name" class="block text-xs font-semibold text-slateink mb-1">Nama Perangkat</label>
                <input id="new-device-name" type="text" name="name" value="{{ old('name') }}" required
                       placeholder="Solar Panel Unit 02"
                       class="w-full min-h-[44px] sm:min-h-[40px] rounded-xl border border-slate-200 bg-canvas px-3.5 py-2 text-sm text-slate-900 focus:border-cobalt-800">
            </div>
            <div>
                <label for="new-device-location" class="block text-xs font-semibold text-slateink mb-1">Lokasi</label>
                <input id="new-device-location" type="text" name="location" value="{{ old('location') }}"
                       placeholder="Gedung Lab B"
                       class="w-full min-h-[44px] sm:min-h-[40px] rounded-xl border border-slate-200 bg-canvas px-3.5 py-2 text-sm text-slate-900 focus:border-cobalt-800">
            </div>
            <div>
                <label for="new-device-interval" class="block text-xs font-semibold text-slateink mb-1">Interval Kirim (detik)</label>
                <input id="new-device-interval" type="number" name="send_interval_sec" value="{{ old('send_interval_sec', 5) }}" min="1" max="3600" required
                       class="w-full min-h-[44px] sm:min-h-[40px] rounded-xl border border-slate-200 bg-canvas px-3.5 py-2 text-sm text-slate-900 focus:border-cobalt-800">
            </div>
            <div>
                <label for="new-device-timeout" class="block text-xs font-semibold text-slateink mb-1">Batas Timeout Offline (detik)</label>
                <input id="new-device-timeout" type="number" name="offline_timeout_sec" value="{{ old('offline_timeout_sec', $defaultOfflineThreshold) }}" min="5" max="86400" required
                       class="w-full min-h-[44px] sm:min-h-[40px] rounded-xl border border-slate-200 bg-canvas px-3.5 py-2 text-sm text-slate-900 focus:border-cobalt-800">
            </div>
            <div>
                <label for="new-device-description" class="block text-xs font-semibold text-slateink mb-1">Deskripsi</label>
                <input id="new-device-description" type="text" name="description" value="{{ old('description') }}"
                       placeholder="Catatan panel surya & beban DC"
                       class="w-full min-h-[44px] sm:min-h-[40px] rounded-xl border border-slate-200 bg-canvas px-3.5 py-2 text-sm text-slate-900 focus:border-cobalt-800">
            </div>
            <div class="sm:col-span-2 lg:col-span-3 flex justify-end gap-2 pt-2">
                <button type="button"
                        onclick="document.getElementById('add-device-panel').classList.add('hidden')"
                        class="min-h-[44px] sm:min-h-[40px] px-4 py-2 rounded-xl border border-slate-200 text-sm font-medium text-slateink hover:bg-slate-50">
                    Batal
                </button>
                <button type="submit"
                        class="min-h-[44px] sm:min-h-[40px] px-5 py-2 rounded-xl hero-cobalt-card text-white text-sm font-semibold transition">
                    Simpan &amp; Buat Token
                </button>
            </div>
        </form>
    </div>

    <!-- Registered Devices List -->
    <div class="space-y-6">
        @forelse ($devices as $item)
            @php
                /** @var \App\Models\Device $device */
                $device = $item['model'];
                $status = $item['status'];
            @endphp
            <div class="instrument-card rounded-3xl overflow-hidden">
                <!-- Card Header -->
                <div class="p-5 sm:p-6 border-b border-slate-100 flex flex-col lg:flex-row lg:items-center lg:justify-between gap-4">
                    <div class="space-y-1">
                        <div class="flex flex-wrap items-center gap-2.5">
                            <h2 class="text-lg font-medium text-slate-900">{{ $device->name }}</h2>
                            <span class="px-2.5 py-0.5 rounded-full bg-cobalt-50 border border-cobalt-100 font-mono text-xs font-semibold text-cobalt-900">
                                {{ $device->device_code }}
                            </span>
                            <span class="inline-flex items-center gap-1.5 px-3 py-0.5 rounded-full text-xs font-semibold border {{ $status['is_online'] ? 'bg-emerald-50 text-emerald-700 border-emerald-200' : 'bg-red-50 text-[#BA1A1A] border-red-200' }}">
                                <span class="w-2 h-2 rounded-full {{ $status['is_online'] ? 'bg-emerald-700' : 'bg-[#BA1A1A]' }}" aria-hidden="true"></span>
                                {{ $status['connection_label'] }}
                            </span>
                            @if (! $device->is_active)
                                <span class="px-2.5 py-0.5 rounded-full text-xs font-semibold bg-amber-50 text-[#92400E] border border-amber-200">
                                    Nonaktif
                                </span>
                            @endif
                        </div>
                        <p class="text-xs text-slateink">
                            {{ $device->location ?: 'Lokasi belum diatur' }}
                            @if ($device->description)
                                &bull; {{ $device->description }}
                            @endif
                        </p>
                    </div>

                    <div class="flex flex-wrap items-center gap-2">
                        <a href="{{ route('dashboard', ['device' => $device->device_code]) }}"
                           class="inline-flex items-center justify-center min-h-[44px] sm:min-h-[38px] px-3.5 py-2 rounded-xl border border-slate-200 text-xs font-semibold text-slate-900 hover:bg-slate-50 transition">
                            Lihat di Dashboard
                        </a>
                        <form method="POST" action="{{ route('devices.rotate-token', $device) }}"
                              onsubmit="return confirm('Buat ulang API token untuk {{ $device->device_code }}? Token lama akan langsung tidak berlaku.');">
                            @csrf
                            <button type="submit"
                                    class="min-h-[44px] sm:min-h-[38px] px-3.5 py-2 rounded-xl bg-amber-50 hover:bg-amber-100 text-[#92400E] border border-amber-200 text-xs font-semibold transition">
                                Rotate API Token
                            </button>
                        </form>
                    </div>
                </div>

                <!-- Telemetry Status Grid -->
                <div class="p-5 sm:p-6 grid grid-cols-1 md:grid-cols-2 xl:grid-cols-4 gap-4 bg-canvas">
                    <div class="bg-white rounded-2xl border border-slate-200/80 p-4">
                        <div class="text-xs text-slateink">Waktu Terakhir Mengirim Data</div>
                        <div class="mt-1 text-sm font-bold text-slate-900 tabular-nums">{{ $status['last_seen_formatted'] }}</div>
                        <div class="mt-0.5 text-xs text-slateink">{{ $status['last_seen_human'] }}</div>
                    </div>

                    <div class="bg-white rounded-2xl border border-slate-200/80 p-4">
                        <div class="text-xs text-slateink">Status Pengiriman Data</div>
                        <div class="mt-1.5">
                            <span class="inline-flex items-center px-2.5 py-1 rounded-full text-xs font-semibold border {{ $status['tx_status']['badge_class'] }}">
                                {{ $status['tx_status']['label'] }}
                            </span>
                        </div>
                        @if ($device->last_tx_error)
                            <div class="mt-1.5 text-xs text-[#BA1A1A] truncate" title="{{ $device->last_tx_error }}">
                                {{ $device->last_tx_error }}
                            </div>
                        @else
                            <div class="mt-1.5 text-xs text-slateink">Total tersimpan: {{ number_format($device->readings_count, 0, ',', '.') }} record</div>
                        @endif
                    </div>

                    <div class="bg-white rounded-2xl border border-slate-200/80 p-4">
                        <div class="text-xs text-slateink">Parameter Koneksi</div>
                        <div class="mt-1 text-sm font-bold text-slate-900">
                            Interval {{ $device->send_interval_sec }} dtk &bull; Timeout {{ $status['offline_timeout_sec'] }} dtk
                        </div>
                        <div class="mt-1 text-xs text-slateink font-mono">
                            Token prefix: {{ $device->api_token_prefix ? $device->api_token_prefix . '...' : '-' }}
                        </div>
                    </div>

                    <div class="bg-white rounded-2xl border border-slate-200/80 p-4">
                        <div class="text-xs text-slateink">Pembacaan Terakhir</div>
                        @if ($device->latestReading)
                            <div class="mt-1 text-sm font-bold text-slate-900 tabular-nums">
                                {{ number_format($device->latestReading->voltage, 2) }} V &bull;
                                {{ number_format($device->latestReading->power, 3) }} W
                            </div>
                            <div class="mt-0.5 text-xs text-slateink tabular-nums">
                                {{ number_format($device->latestReading->temperature, 1) }} &deg;C &bull;
                                {{ number_format($device->latestReading->lux, 0, ',', '.') }} Lux
                            </div>
                        @else
                            <div class="mt-1 text-sm font-semibold text-slateink">Belum ada data</div>
                        @endif
                    </div>
                </div>

                <!-- Sensor Metadata & Quick Edit Form -->
                <div class="p-5 sm:p-6 border-t border-slate-100 grid grid-cols-1 lg:grid-cols-12 gap-6">
                    <!-- Informasi Sensor -->
                    <div class="lg:col-span-5">
                        <h3 class="text-xs font-semibold uppercase tracking-wider text-slateink mb-3">Informasi Sensor Terpasang</h3>
                        @if (is_array($device->sensors_info) && count($device->sensors_info) > 0)
                            <div class="space-y-2 text-xs">
                                @foreach ($device->sensors_info as $key => $sensorLabel)
                                    <div class="flex items-start justify-between gap-3 rounded-2xl bg-cobalt-50 border border-cobalt-100 px-3.5 py-2.5">
                                        <span class="font-semibold text-cobalt-900 capitalize">{{ str_replace('_', ' ', $key) }}</span>
                                        <span class="text-right font-mono text-slate-900">{{ $sensorLabel }}</span>
                                    </div>
                                @endforeach
                            </div>
                        @else
                            <p class="text-xs text-slateink">Belum ada metadata sensor.</p>
                        @endif
                    </div>

                    <!-- Edit Pengaturan Perangkat -->
                    <div class="lg:col-span-7">
                        <h3 class="text-xs font-semibold uppercase tracking-wider text-slateink mb-3">Pengaturan Perangkat</h3>
                        <form method="POST" action="{{ route('devices.update', $device) }}" class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                            @csrf
                            @method('PUT')

                            <div>
                                <label for="device-name-{{ $device->id }}" class="block text-xs font-semibold text-slateink mb-1">Nama Perangkat</label>
                                <input id="device-name-{{ $device->id }}" type="text" name="name" value="{{ $device->name }}" required
                                       class="w-full min-h-[44px] sm:min-h-[40px] rounded-xl border border-slate-200 bg-canvas px-3 py-2 text-sm text-slate-900 focus:border-cobalt-800">
                            </div>

                            <div>
                                <label for="device-location-{{ $device->id }}" class="block text-xs font-semibold text-slateink mb-1">Lokasi</label>
                                <input id="device-location-{{ $device->id }}" type="text" name="location" value="{{ $device->location }}"
                                       class="w-full min-h-[44px] sm:min-h-[40px] rounded-xl border border-slate-200 bg-canvas px-3 py-2 text-sm text-slate-900 focus:border-cobalt-800">
                            </div>

                            <div>
                                <label for="device-interval-{{ $device->id }}" class="block text-xs font-semibold text-slateink mb-1">Interval Kirim (detik)</label>
                                <input id="device-interval-{{ $device->id }}" type="number" name="send_interval_sec" value="{{ $device->send_interval_sec }}" min="1" max="3600" required
                                       class="w-full min-h-[44px] sm:min-h-[40px] rounded-xl border border-slate-200 bg-canvas px-3 py-2 text-sm text-slate-900 focus:border-cobalt-800">
                            </div>

                            <div>
                                <label for="device-timeout-{{ $device->id }}" class="block text-xs font-semibold text-slateink mb-1">Timeout Offline (detik)</label>
                                <input id="device-timeout-{{ $device->id }}" type="number" name="offline_timeout_sec" value="{{ $device->offline_timeout_sec }}" min="5" max="86400" required
                                       class="w-full min-h-[44px] sm:min-h-[40px] rounded-xl border border-slate-200 bg-canvas px-3 py-2 text-sm text-slate-900 focus:border-cobalt-800">
                            </div>

                            <div class="sm:col-span-2">
                                <label for="device-desc-{{ $device->id }}" class="block text-xs font-semibold text-slateink mb-1">Deskripsi</label>
                                <input id="device-desc-{{ $device->id }}" type="text" name="description" value="{{ $device->description }}"
                                       class="w-full min-h-[44px] sm:min-h-[40px] rounded-xl border border-slate-200 bg-canvas px-3 py-2 text-sm text-slate-900 focus:border-cobalt-800">
                            </div>

                            <div class="sm:col-span-2 flex flex-wrap items-center justify-between gap-3 pt-1">
                                <div class="flex items-center gap-2">
                                    <label for="device-active-{{ $device->id }}" class="text-xs font-semibold text-slateink">Status API Perangkat:</label>
                                    <select id="device-active-{{ $device->id }}" name="is_active" class="min-h-[44px] sm:min-h-[40px] rounded-xl border border-slate-200 bg-canvas px-3 py-1.5 text-xs font-semibold text-slate-900 focus:border-cobalt-800">
                                        <option value="1" @selected($device->is_active)>Aktif (Terima Data)</option>
                                        <option value="0" @selected(! $device->is_active)>Nonaktif (Blokir API)</option>
                                    </select>
                                </div>
                                <button type="submit"
                                        class="min-h-[44px] sm:min-h-[40px] px-4 py-2 rounded-xl hero-cobalt-card text-white text-xs font-semibold shadow-sm hover:opacity-95 transition">
                                    Simpan Perubahan
                                </button>
                            </div>
                        </form>
                    </div>
                </div>
            </div>
        @empty
            <div class="instrument-card rounded-3xl p-8 text-center text-sm text-slateink">
                Belum ada perangkat terdaftar.
            </div>
        @endforelse
    </div>
@endsection

@push('scripts')
<script>
    function copyTextToClipboard(text, btn) {
        if (!navigator.clipboard) return;
        navigator.clipboard.writeText(text).then(() => {
            const original = btn.textContent;
            btn.textContent = 'Tersalin!';
            setTimeout(() => { btn.textContent = original; }, 2000);
        });
    }
</script>
@endpush
