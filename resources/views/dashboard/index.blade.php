@extends('layouts.app')

@section('title', 'Dashboard | ' . $siteName)
@section('page_title', 'Dashboard Monitoring Panel Surya')
@section('page_subtitle', 'Monitoring telemetri kelistrikan, intensitas cahaya, dan temperatur panel surya berbasis mikrokontroler ESP32 melalui HTTP REST API dan MySQL.')

@section('page_actions')
    <div class="flex flex-wrap items-center gap-2.5 shrink-0">
        @if ($devices->isNotEmpty())
            <form method="GET" action="{{ route('dashboard') }}" class="flex items-center min-w-0">
                <label for="device-select" class="sr-only">Pilih perangkat ESP32</label>
                <select id="device-select"
                        name="device"
                        onchange="this.form.submit()"
                        class="min-h-[44px] sm:min-h-[40px] max-w-full rounded-xl border border-slate-200 bg-white px-3.5 py-2 text-xs font-semibold text-slate-900 shadow-sm focus:border-cobalt-800">
                    @foreach ($devices as $dev)
                        <option value="{{ $dev->device_code }}" @selected($selectedDevice && $selectedDevice->id === $dev->id)>
                            {{ $dev->name }} ({{ $dev->device_code }})
                        </option>
                    @endforeach
                </select>
            </form>
        @endif

        <a href="{{ route('logs.export.csv', ['device' => $selectedDevice?->device_code]) }}"
           class="inline-flex items-center justify-center gap-1.5 px-4 min-h-[44px] sm:min-h-[40px] rounded-xl bg-white hover:bg-slate-50 text-slate-900 border border-slate-200 text-xs font-semibold shadow-sm transition">
            <svg class="w-4 h-4 text-slateink" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true">
                <path stroke-linecap="round" stroke-linejoin="round" d="M3 16.5v2.25A2.25 2.25 0 0 0 5.25 21h13.5A2.25 2.25 0 0 0 21 18.75V16.5M16.5 12 12 16.5m0 0L7.5 12m4.5 4.5V3"/>
            </svg>
            <span>Ekspor CSV</span>
        </a>

        <a href="{{ route('logs.export.pdf', ['device' => $selectedDevice?->device_code]) }}"
           class="inline-flex items-center justify-center gap-1.5 px-4 min-h-[44px] sm:min-h-[40px] rounded-xl hero-cobalt-card hover:opacity-95 text-white text-xs font-semibold shadow-sm transition">
            <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true">
                <path stroke-linecap="round" stroke-linejoin="round" d="M19.5 14.25v-2.625a3.375 3.375 0 0 0-3.375-3.375h-1.5A1.125 1.125 0 0 1 13.5 7.125v-1.5a3.375 3.375 0 0 0-3.375-3.375H8.25m2.25 0H5.625c-.621 0-1.125.504-1.125 1.125v17.25c0 .621.504 1.125 1.125 1.125h12.75c.621 0 1.125-.504 1.125-1.125V11.25a9 9 0 0 0-9-9Z"/>
            </svg>
            <span>Unduh Laporan PDF</span>
        </a>
    </div>
@endsection

@section('content')
    @php
        $peakPowerToday = $todayAnalysis['metrics']['power']['max'] ?? null;
        $luxVal = $latestReading ? (float) $latestReading->lux : null;
        $luxStatusLabel = match (true) {
            $luxVal === null => 'Menunggu Data',
            $luxVal >= 20000 => 'Cahaya Terik',
            $luxVal >= 5000 => 'Cahaya Optimal',
            default => 'Cahaya Redup',
        };
        $highTemp = $latestReading && $latestReading->temperature >= 55;
        $wh = (float) ($todayAnalysis['energy_wh'] ?? 0);
        $pct = $wh > 0 ? min(100, max(8, ($wh / 50.0) * 100)) : 0;
        $dashOffset = 251.3 - (251.3 * ($pct / 100));
    @endphp

    <!-- Error State Banner (shown on live fetch failure) -->
    <div id="dashboard-error-banner"
         role="alert"
         class="hidden mb-5 rounded-2xl border border-red-200 bg-red-50 px-4 py-3.5 text-sm text-[#BA1A1A] flex flex-col sm:flex-row sm:items-center sm:justify-between gap-3">
        <span>Gagal mengambil pembaruan terbaru dari server. Periksa koneksi jaringan lalu coba sinkronkan ulang.</span>
        <button type="button"
                id="error-retry-btn"
                class="shrink-0 px-3.5 min-h-[44px] rounded-xl bg-[#BA1A1A] hover:bg-red-800 text-white text-xs font-semibold">
            Coba Lagi
        </button>
    </div>

    <!-- Empty State Banner (shown only when no actual readings exist in DB yet) -->
    <div id="no-data-banner"
         class="{{ $latestReading ? 'hidden' : '' }} mb-5 rounded-2xl border border-amber-300 bg-solar-50 px-5 py-4 text-sm text-amber-900">
        <p class="font-bold">Menunggu Data Aktual dari ESP32</p>
        <p class="mt-1 text-slateink">
            Belum ada pembacaan sensor tersimpan di database untuk perangkat ini. Kirim pembacaan dari ESP32 ke
            <code class="px-1.5 py-0.5 rounded bg-white border border-amber-200 font-mono text-xs text-slate-900">POST /api/sensor-data</code>
            untuk menampilkan angka pengukuran aktual.
        </p>
    </div>

    <!-- ROW 1: 4 Instrument KPI Cards (Matches Desktop 4717 Top Row + Mobile 3597 Stacked Hierarchy) -->
    <section aria-label="Ringkasan parameter utama" class="grid grid-cols-1 sm:grid-cols-2 xl:grid-cols-4 gap-5 mb-6">
        <!-- Card 1: Output Daya Aktual (Solid Cobalt Gradient Hero Card) -->
        <div class="hero-cobalt-card rounded-3xl p-5 sm:p-6 text-white shadow-[0_12px_28px_-6px_rgba(30,64,175,0.32)] flex flex-col justify-between min-w-0">
            <div class="flex items-start justify-between gap-3">
                <div>
                    <div class="text-[11px] font-semibold uppercase tracking-wider text-cobalt-100">Output Daya Aktual</div>
                    <div class="text-xs text-cobalt-100 mt-0.5">Sensor INA219 (Beban DC)</div>
                </div>
                <a href="{{ route('monitoring.realtime') }}"
                   aria-label="Buka grafik daya real-time"
                   class="w-9 h-9 rounded-full bg-white/15 hover:bg-white/25 text-white flex items-center justify-center shrink-0 transition">
                    <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.2" aria-hidden="true">
                        <path stroke-linecap="round" stroke-linejoin="round" d="m4.5 19.5 15-15m0 0H8.25m11.25 0v11.25"/>
                    </svg>
                </a>
            </div>

            <div class="my-4 flex items-baseline gap-2">
                <span id="metric-power" class="text-4xl sm:text-[42px] font-light tracking-tight tabular-nums leading-none">
                    {{ $latestReading ? number_format($latestReading->power, 3) : '-' }}
                </span>
                <span class="text-base font-medium text-cobalt-100">Watt</span>
            </div>

            <div class="flex items-center justify-between gap-2 text-xs">
                <span id="dashboard-loading-text"
                      class="inline-flex items-center gap-1.5 px-2.5 py-1 rounded-full bg-cobalt-950/60 text-white font-medium">
                    Sinkron ({{ $refreshIntervalSec }} dtk)
                </span>
                <span class="text-cobalt-100 font-medium tabular-nums">
                    Puncak: <strong id="today-peak-power" class="text-white">{{ $peakPowerToday !== null ? number_format($peakPowerToday, 2) . ' W' : '- W' }}</strong>
                </span>
            </div>
        </div>

        <!-- Card 2: Tegangan & Arus DC (INA219 Dual Readout matching Reference Card 2) -->
        <div class="instrument-card rounded-3xl p-5 sm:p-6 flex flex-col justify-between min-w-0">
            <div class="flex items-start justify-between gap-3">
                <div>
                    <h2 class="text-xs font-semibold text-slateink">Tegangan &amp; Arus DC</h2>
                    <div class="text-xs text-slate-600 mt-0.5">Sensor INA219 (I2C 0x40)</div>
                </div>
                <div class="w-9 h-9 rounded-full bg-cobalt-50 text-cobalt-800 flex items-center justify-center shrink-0" aria-hidden="true">
                    <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                        <path stroke-linecap="round" stroke-linejoin="round" d="m3.75 13.5 10.5-11.25L12 10.5h8.25L9.75 21.75 12 13.5H3.75Z"/>
                    </svg>
                </div>
            </div>

            <div class="my-4 flex flex-wrap items-baseline gap-x-2 gap-y-1">
                <div class="flex items-baseline gap-1">
                    <span id="metric-voltage" class="text-3xl font-light text-slate-900 tracking-tight tabular-nums leading-none">
                        {{ $latestReading ? number_format($latestReading->voltage, 2) : '-' }}
                    </span>
                    <span class="text-sm font-semibold text-slateink">V</span>
                </div>
                <span class="text-slate-300 text-2xl font-light" aria-hidden="true">/</span>
                <div class="flex items-baseline gap-1">
                    <span id="metric-current" class="text-2xl sm:text-[26px] font-medium text-cobalt-800 tracking-tight tabular-nums leading-none">
                        {{ $latestReading ? number_format($latestReading->current, 2) : '-' }}
                    </span>
                    <span class="text-xs font-semibold text-cobalt-800">mA</span>
                </div>
            </div>

            <div class="flex items-center justify-between gap-2 text-xs">
                <span id="device-status-badge"
                      role="status"
                      aria-live="polite"
                      class="inline-flex items-center gap-1.5 px-2.5 py-1 rounded-full font-semibold {{ $isOnline ? 'bg-emerald-50 text-emerald-700' : 'bg-red-50 text-[#BA1A1A]' }}">
                    <span id="device-status-dot" class="w-1.5 h-1.5 rounded-full {{ $isOnline ? 'bg-emerald-700' : 'bg-[#BA1A1A]' }}" aria-hidden="true"></span>
                    <span id="device-status-text">{{ $isOnline ? 'ESP32 Online' : 'ESP32 Offline' }}</span>
                </span>
                <span class="text-slateink font-medium tabular-nums">
                    ID: <strong id="latest-reading-id" class="text-slate-900">{{ $latestReading ? '#' . $latestReading->id : '-' }}</strong>
                </span>
            </div>
        </div>

        <!-- Card 3: Intensitas Cahaya (BH1750 matching Reference Card 3) -->
        <div class="instrument-card rounded-3xl p-5 sm:p-6 flex flex-col justify-between min-w-0">
            <div class="flex items-start justify-between gap-3">
                <div>
                    <h2 class="text-xs font-semibold text-slateink">Intensitas Cahaya</h2>
                    <div class="text-xs text-slate-600 mt-0.5">Sensor BH1750 (I2C 0x23)</div>
                </div>
                <div class="w-9 h-9 rounded-full bg-cobalt-50 text-cobalt-800 flex items-center justify-center shrink-0" aria-hidden="true">
                    <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M12 3v2.25m6.364.386-1.591 1.591M21 12h-2.25m-.386 6.364-1.591-1.591M12 18.75V21m-4.773-4.227-1.591 1.591M5.25 12H3m4.227-4.773L5.636 5.636M15.75 12a3.75 3.75 0 1 1-7.5 0 3.75 3.75 0 0 1 7.5 0Z"/>
                    </svg>
                </div>
            </div>

            <div class="my-4 flex items-baseline gap-1.5">
                <span id="metric-lux" class="text-3xl sm:text-4xl font-light text-slate-900 tracking-tight tabular-nums leading-none">
                    {{ $latestReading ? number_format($latestReading->lux, 0, ',', '.') : '-' }}
                </span>
                <span class="text-sm font-medium text-slateink">Lux</span>
            </div>

            <div class="flex items-center justify-between gap-2 text-xs">
                <span id="lux-status-badge"
                      class="inline-flex items-center gap-1.5 px-2.5 py-1 rounded-full bg-cobalt-50 text-cobalt-800 font-semibold">
                    {{ $luxStatusLabel }}
                </span>
                <span class="text-slateink font-medium">Digital I2C</span>
            </div>
        </div>

        <!-- Card 4: Suhu Panel Surya (DS18B20 matching Reference Card 4) -->
        <div class="instrument-card rounded-3xl p-5 sm:p-6 flex flex-col justify-between min-w-0">
            <div class="flex items-start justify-between gap-3">
                <div>
                    <h2 class="text-xs font-semibold text-slateink">Suhu Panel Surya</h2>
                    <div class="text-xs text-slate-600 mt-0.5">Sensor DS18B20 Probe</div>
                </div>
                <div class="w-9 h-9 rounded-full bg-cobalt-50 text-cobalt-800 flex items-center justify-center shrink-0" aria-hidden="true">
                    <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M14 14.76V5a2 2 0 1 0-4 0v9.76a4 4 0 1 0 4 0Z"/>
                    </svg>
                </div>
            </div>

            <div class="my-4 flex items-baseline gap-1">
                <span id="metric-temperature" class="text-3xl sm:text-4xl font-light text-slate-900 tracking-tight tabular-nums leading-none">
                    {{ $latestReading ? number_format($latestReading->temperature, 1) : '-' }}
                </span>
                <span class="text-base font-medium text-slateink">&deg;C</span>
            </div>

            <div class="flex items-center justify-between gap-2 text-xs">
                <span id="temp-status-label"
                      class="inline-flex items-center gap-1.5 px-2.5 py-1 rounded-full font-semibold {{ $latestReading ? ($highTemp ? 'bg-red-50 text-[#BA1A1A]' : 'bg-cobalt-50 text-cobalt-800') : 'bg-slate-100 text-slateink' }}">
                    <span class="w-1.5 h-1.5 rounded-full {{ $latestReading ? ($highTemp ? 'bg-[#BA1A1A]' : 'bg-cobalt-800') : 'bg-slate-400' }}" aria-hidden="true"></span>
                    <span>{{ $latestReading ? ($highTemp ? 'Suhu Tinggi (>=55°C)' : 'Normal (< 55°C)') : 'Menunggu Data' }}</span>
                </span>
                <span class="text-slateink font-medium font-mono">GPIO 4</span>
            </div>
        </div>
    </section>

    <!-- ROW 2: 3-Column Bento Grid (5 / 3 / 4 Columns - Matches Desktop 4717 Middle Row) -->
    <section aria-label="Grafik daya dan status perangkat" class="grid grid-cols-1 lg:grid-cols-12 gap-5 mb-6">
        <!-- Left (5 cols): Profil Daya Terbaru Bar Chart + Segmented Controls -->
        <div class="lg:col-span-5 instrument-card rounded-3xl p-5 sm:p-6 flex flex-col justify-between min-w-0">
            <div class="flex flex-wrap items-start justify-between gap-3 mb-4">
                <div>
                    <h2 class="text-base sm:text-lg font-medium text-slate-900">Profil Output Daya</h2>
                    <p class="text-xs text-slateink mt-0.5">Sampel pembacaan daya (Watt) dari MySQL</p>
                </div>

                <!-- Interactive Segmented Period Tabs -->
                <div class="inline-flex items-center p-1 rounded-xl bg-slate-100 border border-slate-200/70" role="group" aria-label="Pilih rentang grafik cepat">
                    <button type="button" data-mini-range="latest" class="mini-range-btn px-2.5 py-1 rounded-lg text-xs font-semibold bg-white text-slate-900 shadow-sm transition">
                        Terbaru
                    </button>
                    <button type="button" data-mini-range="1h" class="mini-range-btn px-2.5 py-1 rounded-lg text-xs font-medium text-slateink hover:text-slate-900 transition">
                        1 Jam
                    </button>
                    <button type="button" data-mini-range="6h" class="mini-range-btn px-2.5 py-1 rounded-lg text-xs font-medium text-slateink hover:text-slate-900 transition">
                        6 Jam
                    </button>
                </div>
            </div>

            <div class="flex items-center justify-between mb-2">
                <span class="inline-flex items-center px-2.5 py-1 rounded-lg bg-cobalt-800 text-white text-[11px] font-semibold tabular-nums">
                    Terbaru: <span id="chart-callout-power" class="ml-1">{{ $latestReading ? number_format($latestReading->power, 3) . ' W' : '- W' }}</span>
                </span>
                <a href="{{ route('monitoring.realtime') }}" class="text-xs font-semibold text-cobalt-800 hover:underline">
                    5 Grafik Live &rarr;
                </a>
            </div>

            <div class="relative h-52 sm:h-56">
                <canvas id="dashboard-mini-chart" aria-label="Grafik batang output daya terbaru dalam Watt" role="img"></canvas>
            </div>

            <div class="pt-3 mt-3 border-t border-slate-100 flex flex-wrap items-center justify-between gap-2 text-xs text-slateink">
                <div class="flex items-center gap-3">
                    <span class="inline-flex items-center gap-1.5">
                        <span class="w-2.5 h-2.5 rounded-full bg-cobalt-800" aria-hidden="true"></span>
                        <span>Pembacaan Terbaru</span>
                    </span>
                    <span class="inline-flex items-center gap-1.5">
                        <span class="w-2.5 h-2.5 rounded-full bg-cobalt-200" aria-hidden="true"></span>
                        <span>Sampel Sebelumnya</span>
                    </span>
                </div>
                <span class="font-semibold text-cobalt-800 tabular-nums">
                    Total Energi: {{ number_format($todayAnalysis['energy_wh'], 2) }} Wh
                </span>
            </div>
        </div>

        <!-- Middle (3 cols): Status Node ESP32 Card (Matches Reference Middle Card) -->
        <div class="lg:col-span-3 instrument-card rounded-3xl p-5 sm:p-6 flex flex-col justify-between gap-4 min-w-0">
            <div>
                <div class="text-[11px] font-semibold uppercase tracking-wider text-slateink">Status Node ESP32</div>
                <h2 class="mt-1 text-lg font-medium text-slate-900 leading-snug">
                    {{ $selectedDevice?->name ?? 'ESP32 Solar Unit' }}
                </h2>
                <p class="mt-1 text-xs text-slateink">
                    {{ $selectedDevice?->device_code ?? '-' }} &bull; {{ $selectedDevice?->location ?: 'Lokasi belum diatur' }}
                </p>

                <dl class="mt-4 rounded-2xl bg-cobalt-50 border border-cobalt-100 p-4 space-y-2.5 text-xs">
                    <div class="flex items-center justify-between gap-2">
                        <dt class="text-slateink">Update Terakhir</dt>
                        <dd id="last-updated-text" class="font-semibold text-slate-900 tabular-nums text-right">
                            {{ $latestReading ? $latestReading->recorded_at->format('H:i:s d/m') : 'Belum ada data' }}
                        </dd>
                    </div>
                    <div class="flex items-center justify-between gap-2">
                        <dt class="text-slateink">Interval Kirim</dt>
                        <dd class="font-semibold text-cobalt-800 tabular-nums">{{ $selectedDevice?->send_interval_sec ?? 5 }} dtk</dd>
                    </div>
                    <div class="flex items-center justify-between gap-2">
                        <dt class="text-slateink">Protokol</dt>
                        <dd class="font-mono font-semibold text-slate-900">HTTP REST</dd>
                    </div>
                    <div class="flex items-center justify-between gap-2">
                        <dt class="text-slateink">Record MySQL</dt>
                        <dd id="total-readings-count" class="font-semibold text-cobalt-800 tabular-nums">
                            {{ number_format($totalReadings, 0, ',', '.') }} baris
                        </dd>
                    </div>
                </dl>
            </div>

            <a href="{{ route('devices.index') }}"
               class="inline-flex items-center justify-center gap-2 w-full min-h-[44px] rounded-xl hero-cobalt-card text-white text-xs font-semibold shadow-sm hover:opacity-95 transition">
                <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M10.5 6h9.75M10.5 6a1.5 1.5 0 1 1-3 0m3 0a1.5 1.5 0 1 0-3 0M3.75 6H7.5m3 12h9.75m-9.75 0a1.5 1.5 0 0 1-3 0m3 0a1.5 1.5 0 0 0-3 0m-3.75 0H7.5m9-6h3.75m-3.75 0a1.5 1.5 0 0 1-3 0m3 0a1.5 1.5 0 0 0-3 0m-9.75 0h9.75"/>
                </svg>
                <span>Detail &amp; Konfigurasi Node</span>
            </a>
        </div>

        <!-- Right (4 cols): Sensor Terpasang List (Matches Reference Right Card 4717) -->
        <div class="lg:col-span-4 instrument-card rounded-3xl p-5 sm:p-6 flex flex-col justify-between gap-4 min-w-0">
            <div>
                <div class="flex items-start justify-between gap-2 mb-4">
                    <div>
                        <h2 class="text-base sm:text-lg font-medium text-slate-900">Sensor Terpasang</h2>
                        <p class="text-xs text-slateink mt-0.5">4 Modul Hardware Terkonfigurasi</p>
                    </div>
                    <span class="px-2.5 py-1 rounded-full bg-cobalt-50 text-cobalt-800 text-xs font-semibold">I2C &amp; 1-Wire</span>
                </div>

                <div class="space-y-2.5 text-xs">
                    <!-- INA219 -->
                    <div class="flex items-center justify-between gap-3 p-2.5 rounded-2xl bg-canvas border border-slate-200/70">
                        <div class="flex items-center gap-3 min-w-0">
                            <div class="w-9 h-9 rounded-full bg-cobalt-50 text-cobalt-800 flex items-center justify-center shrink-0 font-bold" aria-hidden="true">I2C</div>
                            <div class="min-w-0">
                                <div class="font-semibold text-slate-900 truncate">INA219 DC Bi-Dir</div>
                                <div class="text-[11px] text-slateink truncate">Addr 0x40 &bull; V / mA / W</div>
                            </div>
                        </div>
                        <span class="px-2.5 py-1 rounded-full text-[11px] font-semibold shrink-0 {{ $latestReading ? 'bg-cobalt-50 text-cobalt-800' : 'bg-slate-100 text-slateink' }}">
                            {{ $latestReading ? 'Aktif' : 'Standby' }}
                        </span>
                    </div>

                    <!-- BH1750 -->
                    <div class="flex items-center justify-between gap-3 p-2.5 rounded-2xl bg-canvas border border-slate-200/70">
                        <div class="flex items-center gap-3 min-w-0">
                            <div class="w-9 h-9 rounded-full bg-cobalt-50 text-cobalt-800 flex items-center justify-center shrink-0 font-bold" aria-hidden="true">LUX</div>
                            <div class="min-w-0">
                                <div class="font-semibold text-slate-900 truncate">BH1750 Ambient</div>
                                <div class="text-[11px] text-slateink truncate">Addr 0x23 &bull; Continuous</div>
                            </div>
                        </div>
                        <span class="px-2.5 py-1 rounded-full text-[11px] font-semibold shrink-0 {{ $latestReading ? 'bg-cobalt-50 text-cobalt-800' : 'bg-slate-100 text-slateink' }}">
                            {{ $latestReading ? 'Aktif' : 'Standby' }}
                        </span>
                    </div>

                    <!-- DS18B20 -->
                    <div class="flex items-center justify-between gap-3 p-2.5 rounded-2xl bg-canvas border border-slate-200/70">
                        <div class="flex items-center gap-3 min-w-0">
                            <div class="w-9 h-9 rounded-full bg-cobalt-50 text-cobalt-800 flex items-center justify-center shrink-0 font-bold" aria-hidden="true">1W</div>
                            <div class="min-w-0">
                                <div class="font-semibold text-slate-900 truncate">DS18B20 Waterproof</div>
                                <div class="text-[11px] text-slateink truncate">GPIO 4 &bull; Pull-up 4.7k&Omega;</div>
                            </div>
                        </div>
                        <span class="px-2.5 py-1 rounded-full text-[11px] font-semibold shrink-0 {{ $latestReading ? 'bg-emerald-50 text-emerald-700' : 'bg-slate-100 text-slateink' }}">
                            {{ $latestReading ? 'Normal' : 'Standby' }}
                        </span>
                    </div>

                    <!-- LCD 16x2 -->
                    <div class="flex items-center justify-between gap-3 p-2.5 rounded-2xl bg-canvas border border-slate-200/70">
                        <div class="flex items-center gap-3 min-w-0">
                            <div class="w-9 h-9 rounded-full bg-cobalt-50 text-cobalt-800 flex items-center justify-center shrink-0 font-bold" aria-hidden="true">LCD</div>
                            <div class="min-w-0">
                                <div class="font-semibold text-slate-900 truncate">LCD 16x2 Karakter</div>
                                <div class="text-[11px] text-slateink truncate">I2C Display &bull; SDA 21 / SCL 22</div>
                            </div>
                        </div>
                        <span class="px-2.5 py-1 rounded-full text-[11px] font-semibold shrink-0 bg-cobalt-50 text-cobalt-800">
                            Siap
                        </span>
                    </div>
                </div>
            </div>

            <div class="pt-3 border-t border-slate-100 flex items-center justify-between text-xs text-slateink">
                <span>Pinout Bus: SDA 21 / SCL 22</span>
                <span class="font-semibold text-cobalt-800">MySQL Persisted</span>
            </div>
        </div>
    </section>

    <!-- ROW 3: 3-Column Bento Grid (6 / 3 / 3 Columns - Matches Desktop 4717 Bottom Row) -->
    <section aria-label="Riwayat telemetri, energi, dan kontrol polling" class="grid grid-cols-1 lg:grid-cols-12 gap-5">
        <!-- Left (6 cols): Riwayat Telemetri & Log MySQL -->
        <div class="lg:col-span-6 instrument-card rounded-3xl p-5 sm:p-6 flex flex-col justify-between min-w-0">
            <div>
                <div class="flex flex-wrap items-center justify-between gap-3 mb-4">
                    <div>
                        <h2 class="text-base sm:text-lg font-medium text-slate-900">Riwayat Telemetri MySQL</h2>
                        <p class="text-xs text-slateink mt-0.5">Pembacaan terbaru yang tersimpan melalui REST API</p>
                    </div>
                    <a href="{{ route('logs.index') }}"
                       class="inline-flex items-center gap-1.5 px-3.5 min-h-[38px] rounded-full bg-cobalt-50 hover:bg-cobalt-100 text-cobalt-800 text-xs font-semibold transition">
                        <span>Buka Semua Log</span>
                    </a>
                </div>

                <div id="recent-logs-list" class="space-y-2.5">
                    @forelse ($recentReadings as $row)
                        @php $rowHighTemp = $row->temperature >= 55; @endphp
                        <div class="flex flex-wrap sm:flex-nowrap items-center justify-between gap-3 rounded-2xl bg-canvas border border-slate-200/70 px-4 py-3 text-xs">
                            <div class="flex items-center gap-3 min-w-0">
                                <span class="w-9 h-9 rounded-full {{ $loop->first ? 'bg-cobalt-800 text-white' : 'bg-cobalt-100 text-cobalt-900' }} flex items-center justify-center font-mono text-[11px] font-bold shrink-0">
                                    #{{ $row->id }}
                                </span>
                                <div class="min-w-0">
                                    <div class="font-semibold text-slate-900 tabular-nums">
                                        Log #{{ $row->id }} &bull; {{ $row->recorded_at->format('H:i:s') }} WIB
                                    </div>
                                    <div class="text-slateink tabular-nums mt-0.5">
                                        {{ number_format($row->voltage, 2) }} V &bull;
                                        {{ number_format($row->current, 1) }} mA &bull;
                                        <strong class="text-cobalt-800">{{ number_format($row->power, 3) }} W</strong>
                                    </div>
                                </div>
                            </div>

                            <div class="flex items-center gap-3 shrink-0 ml-auto">
                                <div class="text-right tabular-nums text-slateink hidden sm:block">
                                    <div>{{ number_format($row->lux, 0, ',', '.') }} Lux</div>
                                    <div>{{ number_format($row->temperature, 1) }} &deg;C</div>
                                </div>
                                <span class="px-2.5 py-1 rounded-full text-[11px] font-semibold {{ $rowHighTemp ? 'bg-red-50 text-[#BA1A1A]' : 'bg-cobalt-50 text-cobalt-800' }}">
                                    {{ $rowHighTemp ? 'Suhu Tinggi' : 'Normal' }}
                                </span>
                            </div>
                        </div>
                    @empty
                        <div class="rounded-2xl bg-canvas border border-dashed border-slate-300 p-6 text-center text-xs text-slateink">
                            Belum ada data riwayat di tabel <code class="font-mono">sensor_readings</code>. Data akan muncul begitu ESP32 berhasil mengirim pembacaan pertama.
                        </div>
                    @endforelse
                </div>
            </div>

            <div class="pt-3 mt-4 border-t border-slate-100 flex flex-wrap items-center justify-between gap-2 text-xs text-slateink">
                <span>Tabel: <code class="font-mono text-slate-900">sensor_readings</code></span>
                <span class="font-semibold text-slate-900 tabular-nums">{{ number_format($totalReadings, 0, ',', '.') }} total baris</span>
            </div>
        </div>

        <!-- Middle (3 cols): Akumulasi Energi Hari Ini (Semicircle Gauge Card matching 4717) -->
        <div class="lg:col-span-3 instrument-card rounded-3xl p-5 sm:p-6 flex flex-col justify-between gap-4 min-w-0">
            <div class="flex items-center justify-between gap-2">
                <div>
                    <h2 class="text-base font-medium text-slate-900 leading-snug">Akumulasi Energi</h2>
                    <p class="text-xs text-slateink mt-0.5">Integrasi Trapezoidal Hari Ini</p>
                </div>
                <a href="{{ route('analysis.index') }}" class="text-xs font-semibold text-cobalt-800 hover:underline shrink-0">Detail</a>
            </div>

            <div class="flex flex-col items-center py-1">
                <div class="relative w-40 h-20 overflow-hidden">
                    <svg viewBox="0 0 200 105" width="160" height="80" class="w-full h-full" aria-hidden="true">
                        <path d="M 20 95 A 80 80 0 0 1 180 95"
                              fill="none" stroke="#EFF4FF" stroke-width="16" stroke-linecap="round"/>
                        <path id="energy-gauge-arc"
                              d="M 20 95 A 80 80 0 0 1 180 95"
                              fill="none" stroke="#1E40AF" stroke-width="16" stroke-linecap="round"
                              stroke-dasharray="251.3" stroke-dashoffset="{{ number_format($dashOffset, 1, '.', '') }}"/>
                    </svg>
                </div>
                <div class="mt-2 text-center">
                    <div id="today-energy-wh" class="text-xl xl:text-2xl font-light text-slate-900 tabular-nums leading-tight">
                        {{ number_format($todayAnalysis['energy_wh'], 4) }} Wh
                    </div>
                    <div id="today-energy-kwh" class="text-xs text-slateink tabular-nums mt-0.5">
                        {{ number_format($todayAnalysis['energy_kwh'], 5) }} kWh
                    </div>
                </div>
            </div>

            <div class="pt-3 border-t border-slate-100 space-y-1.5 text-xs text-slateink">
                <div class="flex items-center justify-between">
                    <span>Durasi terintegrasi</span>
                    <strong id="today-energy-duration" class="text-slate-900 tabular-nums">{{ gmdate('H:i:s', $todayAnalysis['integrated_seconds']) }}</strong>
                </div>
                <div class="flex items-center justify-between">
                    <span>Jeda offline dilewati</span>
                    <strong id="today-energy-gaps" class="text-slate-900 tabular-nums">{{ $todayAnalysis['skipped_gaps'] }}</strong>
                </div>
            </div>
        </div>

        <!-- Right (3 cols): Live Telemetry Control Card (Solid Cobalt matching 4717 Bottom-Right Card) -->
        <div class="lg:col-span-3 hero-cobalt-card rounded-3xl p-5 sm:p-6 text-white shadow-[0_12px_28px_-6px_rgba(30,64,175,0.32)] flex flex-col justify-between gap-4 min-w-0">
            <div class="flex items-center justify-between gap-2 text-xs">
                <span class="font-semibold uppercase tracking-wider text-cobalt-100">HTTP Polling</span>
                <span id="sync-state-label" class="font-semibold text-white">Aktif ({{ $refreshIntervalSec }} dtk)</span>
            </div>

            <div class="text-center py-2">
                <div id="sync-clock-text" class="text-3xl sm:text-4xl font-light tracking-tight text-white tabular-nums">
                    {{ $latestReading ? $latestReading->recorded_at->format('H:i:s') : '--:--:--' }}
                </div>
                <p class="mt-1 text-xs text-cobalt-100">Sinkronisasi Terakhir (WIB)</p>
            </div>

            <div class="grid grid-cols-2 gap-2.5">
                <button type="button"
                        id="dashboard-toggle-live"
                        aria-pressed="true"
                        class="min-h-[44px] px-3 rounded-xl bg-white hover:bg-cobalt-50 text-cobalt-900 text-xs font-semibold transition">
                    Jeda Auto-Refresh
                </button>
                <button type="button"
                        id="dashboard-sync-now"
                        class="min-h-[44px] px-3 rounded-xl bg-cobalt-950/70 hover:bg-cobalt-950 text-white border border-white/25 text-xs font-semibold transition">
                    Sinkronkan Sekarang
                </button>
            </div>

            <div class="pt-3 border-t border-white/15 flex items-center justify-between text-[11px] text-cobalt-100">
                <span>Timeout: {{ $offlineTimeoutSec }} dtk</span>
                <span>Endpoint: /dashboard/latest</span>
            </div>
        </div>
    </section>
@endsection

@push('scripts')
<script>
    (function () {
        const refreshMs = {{ $refreshIntervalSec * 1000 }};
        const selectedCode = @json($selectedDevice?->device_code);
        const chartDataUrl = @json(route('monitoring.chart-data'));
        const initialMini = @json($miniChart);
        let livePolling = true;
        let activeMiniRange = 'latest';
        let miniChartInstance = null;

        function bootMiniChart() {
            const ctx = document.getElementById('dashboard-mini-chart');
            if (!ctx) return;
            if (typeof window.Chart === 'undefined') {
                setTimeout(bootMiniChart, 50);
                return;
            }

            miniChartInstance = new window.Chart(ctx, {
                type: 'bar',
                data: {
                    labels: initialMini.labels || [],
                    datasets: [{
                        label: 'Daya (W)',
                        data: initialMini.power || [],
                        backgroundColor: (initialMini.power || []).map((_, idx, arr) =>
                            idx === arr.length - 1 ? '#1E40AF' : '#93C5FD'
                        ),
                        borderRadius: 999,
                        borderSkipped: false,
                        barPercentage: 0.56,
                    }],
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    animation: false,
                    plugins: { legend: { display: false } },
                    scales: {
                        x: { grid: { display: false }, ticks: { color: '#444653', font: { size: 11 } } },
                        y: { beginAtZero: true, grid: { color: '#EFF4FF' }, ticks: { color: '#444653', font: { size: 11 } } },
                    },
                },
            });
        }

        function formatNumber(val, decimals) {
            return Number(val).toLocaleString('en-US', {
                minimumFractionDigits: decimals,
                maximumFractionDigits: decimals,
            });
        }

        function formatDuration(totalSec) {
            const h = String(Math.floor(totalSec / 3600)).padStart(2, '0');
            const m = String(Math.floor((totalSec % 3600) / 60)).padStart(2, '0');
            const s = String(totalSec % 60).padStart(2, '0');
            return `${h}:${m}:${s}`;
        }

        function updateStatusBadge(isOnline) {
            const badge = document.getElementById('device-status-badge');
            const dot = document.getElementById('device-status-dot');
            const text = document.getElementById('device-status-text');
            if (!badge || !dot || !text) return;

            if (isOnline) {
                badge.className = 'inline-flex items-center gap-1.5 px-2.5 py-1 rounded-full font-semibold bg-emerald-50 text-emerald-700';
                dot.className = 'w-1.5 h-1.5 rounded-full bg-emerald-700';
                text.textContent = 'ESP32 Online';
            } else {
                badge.className = 'inline-flex items-center gap-1.5 px-2.5 py-1 rounded-full font-semibold bg-red-50 text-[#BA1A1A]';
                dot.className = 'w-1.5 h-1.5 rounded-full bg-[#BA1A1A]';
                text.textContent = 'ESP32 Offline';
            }
        }

        function setMiniChartData(labels, values) {
            if (!miniChartInstance) return;
            miniChartInstance.data.labels = labels || [];
            miniChartInstance.data.datasets[0].data = values || [];
            miniChartInstance.data.datasets[0].backgroundColor = (values || []).map((_, idx, arr) =>
                idx === arr.length - 1 ? '#1E40AF' : '#93C5FD'
            );
            miniChartInstance.update('none');
        }

        async function loadRangeChart(rangeKey) {
            if (!selectedCode) return;
            if (rangeKey === 'latest') {
                pollLatest();
                return;
            }
            try {
                const res = await fetch(`${chartDataUrl}?device=${encodeURIComponent(selectedCode)}&period=${encodeURIComponent(rangeKey)}`, {
                    headers: { 'Accept': 'application/json' },
                });
                if (!res.ok) return;
                const payload = await res.json();
                setMiniChartData(payload.labels || [], (payload.series && payload.series.power) ? payload.series.power : []);
            } catch (_) {
                // Retain existing chart bars on transient errors
            }
        }

        document.querySelectorAll('.mini-range-btn').forEach((btn) => {
            btn.addEventListener('click', () => {
                activeMiniRange = btn.dataset.miniRange;
                document.querySelectorAll('.mini-range-btn').forEach((b) => {
                    const active = b === btn;
                    b.className = active
                        ? 'mini-range-btn px-2.5 py-1 rounded-lg text-xs font-semibold bg-white text-slate-900 shadow-sm transition'
                        : 'mini-range-btn px-2.5 py-1 rounded-lg text-xs font-medium text-slateink hover:text-slate-900 transition';
                });
                loadRangeChart(activeMiniRange);
            });
        });

        async function pollLatest() {
            if (!selectedCode) return;
            const endpoint = @json(route('dashboard.latest')) + '?device=' + encodeURIComponent(selectedCode);
            const loadingText = document.getElementById('dashboard-loading-text');
            const errorBanner = document.getElementById('dashboard-error-banner');

            if (loadingText) loadingText.textContent = 'Memuat...';

            try {
                const res = await fetch(endpoint, { headers: { 'Accept': 'application/json' } });
                if (!res.ok) throw new Error('HTTP ' + res.status);
                const data = await res.json();

                if (errorBanner) errorBanner.classList.add('hidden');
                if (loadingText) loadingText.textContent = 'Sinkron ({{ $refreshIntervalSec }} dtk)';

                if (data.device) {
                    updateStatusBadge(Boolean(data.device.is_online));
                }

                const totalEl = document.getElementById('total-readings-count');
                if (totalEl && typeof data.total_readings === 'number') {
                    totalEl.textContent = Number(data.total_readings).toLocaleString('id-ID') + ' baris';
                }

                const banner = document.getElementById('no-data-banner');
                if (data.reading) {
                    if (banner) banner.classList.add('hidden');
                    document.getElementById('metric-voltage').textContent = formatNumber(data.reading.voltage, 2);
                    document.getElementById('metric-current').textContent = formatNumber(data.reading.current, 2);
                    document.getElementById('metric-power').textContent = formatNumber(data.reading.power, 3);
                    document.getElementById('metric-temperature').textContent = formatNumber(data.reading.temperature, 1);
                    document.getElementById('metric-lux').textContent = Math.round(Number(data.reading.lux)).toLocaleString('id-ID');

                    const idEl = document.getElementById('latest-reading-id');
                    if (idEl) idEl.textContent = '#' + data.reading.id;

                    const calloutEl = document.getElementById('chart-callout-power');
                    if (calloutEl) calloutEl.textContent = formatNumber(data.reading.power, 3) + ' W';

                    const luxVal = Number(data.reading.lux);
                    const luxBadge = document.getElementById('lux-status-badge');
                    if (luxBadge) {
                        luxBadge.textContent = luxVal >= 20000 ? 'Cahaya Terik' : (luxVal >= 5000 ? 'Cahaya Optimal' : 'Cahaya Redup');
                    }

                    document.getElementById('last-updated-text').textContent = data.reading.recorded_at_formatted;
                    document.getElementById('sync-clock-text').textContent = data.reading.recorded_at_clock || '--:--:--';

                    const highTemp = Number(data.reading.temperature) >= 55;
                    const tempLabel = document.getElementById('temp-status-label');
                    if (tempLabel) {
                        tempLabel.className = 'inline-flex items-center gap-1.5 px-2.5 py-1 rounded-full font-semibold ' +
                            (highTemp ? 'bg-red-50 text-[#BA1A1A]' : 'bg-cobalt-50 text-cobalt-800');
                        tempLabel.innerHTML = `<span class="w-1.5 h-1.5 rounded-full ${highTemp ? 'bg-[#BA1A1A]' : 'bg-cobalt-800'}" aria-hidden="true"></span><span>${highTemp ? 'Suhu Tinggi (>=55°C)' : 'Normal (< 55°C)'}</span>`;
                    }
                } else if (banner) {
                    banner.classList.remove('hidden');
                }

                if (activeMiniRange === 'latest' && data.mini_chart) {
                    setMiniChartData(data.mini_chart.labels || [], data.mini_chart.power || []);
                }

                if (data.today_energy) {
                    const wh = Number(data.today_energy.energy_wh || 0);
                    document.getElementById('today-energy-wh').textContent = formatNumber(wh, 4) + ' Wh';
                    document.getElementById('today-energy-kwh').textContent = formatNumber(data.today_energy.energy_kwh || 0, 5) + ' kWh';
                    document.getElementById('today-energy-duration').textContent = formatDuration(Number(data.today_energy.integrated_seconds || 0));
                    document.getElementById('today-energy-gaps').textContent = String(data.today_energy.skipped_gaps || 0);

                    const peakEl = document.getElementById('today-peak-power');
                    if (peakEl) {
                        peakEl.textContent = data.today_energy.peak_power !== null && data.today_energy.peak_power !== undefined
                            ? formatNumber(data.today_energy.peak_power, 2) + ' W'
                            : '- W';
                    }

                    const arc = document.getElementById('energy-gauge-arc');
                    if (arc) {
                        const pct = wh > 0 ? Math.min(100, Math.max(8, (wh / 50.0) * 100)) : 0;
                        const offset = 251.3 - (251.3 * (pct / 100));
                        arc.setAttribute('stroke-dashoffset', offset.toFixed(1));
                    }
                }

                const logsList = document.getElementById('recent-logs-list');
                if (logsList && Array.isArray(data.recent_readings) && data.recent_readings.length > 0) {
                    logsList.innerHTML = data.recent_readings.map((row, idx) => {
                        const isHigh = Number(row.temperature ?? 0) >= 55;
                        return `
                            <div class="flex flex-wrap sm:flex-nowrap items-center justify-between gap-3 rounded-2xl bg-canvas border border-slate-200/70 px-4 py-3 text-xs">
                                <div class="flex items-center gap-3 min-w-0">
                                    <span class="w-9 h-9 rounded-full ${idx === 0 ? 'bg-cobalt-800 text-white' : 'bg-cobalt-100 text-cobalt-900'} flex items-center justify-center font-mono text-[11px] font-bold shrink-0">
                                        #${row.id}
                                    </span>
                                    <div class="min-w-0">
                                        <div class="font-semibold text-slate-900 tabular-nums">Log #${row.id} &bull; ${row.clock} WIB</div>
                                        <div class="text-slateink tabular-nums mt-0.5">
                                            ${formatNumber(row.voltage, 2)} V &bull; ${formatNumber(row.current ?? 0, 1)} mA &bull;
                                            <strong class="text-cobalt-800">${formatNumber(row.power, 3)} W</strong>
                                        </div>
                                    </div>
                                </div>
                                <div class="flex items-center gap-3 shrink-0 ml-auto">
                                    <div class="text-right tabular-nums text-slateink hidden sm:block">
                                        <div>${Math.round(Number(row.lux ?? 0)).toLocaleString('id-ID')} Lux</div>
                                        <div>${formatNumber(row.temperature ?? 0, 1)} &deg;C</div>
                                    </div>
                                    <span class="px-2.5 py-1 rounded-full text-[11px] font-semibold ${isHigh ? 'bg-red-50 text-[#BA1A1A]' : 'bg-cobalt-50 text-cobalt-800'}">
                                        ${isHigh ? 'Suhu Tinggi' : 'Normal'}
                                    </span>
                                </div>
                            </div>
                        `;
                    }).join('');
                }
            } catch (err) {
                if (loadingText) loadingText.textContent = 'Terputus';
                if (errorBanner) errorBanner.classList.remove('hidden');
            }
        }

        const toggleBtn = document.getElementById('dashboard-toggle-live');
        const syncLabel = document.getElementById('sync-state-label');
        if (toggleBtn && syncLabel) {
            toggleBtn.addEventListener('click', () => {
                livePolling = !livePolling;
                toggleBtn.setAttribute('aria-pressed', livePolling ? 'true' : 'false');
                if (livePolling) {
                    toggleBtn.textContent = 'Jeda Auto-Refresh';
                    syncLabel.textContent = 'Aktif ({{ $refreshIntervalSec }} dtk)';
                    pollLatest();
                } else {
                    toggleBtn.textContent = 'Lanjut Auto-Refresh';
                    syncLabel.textContent = 'Dijeda';
                }
            });
        }

        const syncBtn = document.getElementById('dashboard-sync-now');
        if (syncBtn) syncBtn.addEventListener('click', pollLatest);

        const retryBtn = document.getElementById('error-retry-btn');
        if (retryBtn) retryBtn.addEventListener('click', pollLatest);

        bootMiniChart();
        setInterval(() => {
            if (livePolling) pollLatest();
        }, refreshMs);
    })();
</script>
@endpush
