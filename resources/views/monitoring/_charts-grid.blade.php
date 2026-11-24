@section('page_actions')
    <div class="flex flex-wrap items-center gap-2 shrink-0">
        @if ($mode === 'realtime')
            <button type="button" id="toggle-live-btn"
                    class="inline-flex items-center justify-center gap-2 min-h-[44px] sm:min-h-[40px] px-4 py-2 rounded-xl text-xs font-semibold bg-emerald-50 text-emerald-700 border border-emerald-200 hover:bg-emerald-100 shadow-sm transition">
                <span id="live-indicator-dot" class="w-2 h-2 rounded-full bg-emerald-700" aria-hidden="true"></span>
                <span id="live-indicator-text">Auto-Update Aktif ({{ $refreshIntervalSec }} dtk)</span>
            </button>
        @endif
        <button type="button" id="manual-refresh-btn"
                class="inline-flex items-center justify-center gap-1.5 min-h-[44px] sm:min-h-[40px] px-4 py-2 rounded-xl text-xs font-semibold hero-cobalt-card text-white shadow-sm hover:opacity-95 transition">
            <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true">
                <path stroke-linecap="round" stroke-linejoin="round" d="M4 4v5h.582m15.356 2A8.001 8.001 0 0 0 4.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 0 1-15.357-2m15.357 2H15"/>
            </svg>
            <span>Muat Ulang Grafik</span>
        </button>
    </div>
@endsection

<!-- Filter & Period Controls Bar (Rounded-3xl Matching FigCraft Desktop & Mobile) -->
<div class="instrument-card rounded-3xl p-4 sm:p-6 mb-6 space-y-4">
    <div class="flex flex-col lg:flex-row lg:items-center lg:justify-between gap-4 min-w-0">
        <div class="flex flex-wrap items-center gap-3 min-w-0">
            @if ($devices->isNotEmpty())
                <div class="flex flex-wrap sm:flex-nowrap items-center gap-2 w-full sm:w-auto min-w-0">
                    <label for="chart-device-select" class="text-xs font-semibold uppercase tracking-wider text-slateink shrink-0">Perangkat:</label>
                    <select id="chart-device-select"
                            class="w-full sm:w-auto max-w-full min-w-0 min-h-[44px] sm:min-h-[40px] rounded-xl border border-slate-200 bg-canvas px-3.5 py-2 text-sm font-semibold text-slate-900 focus:border-cobalt-800">
                        @foreach ($devices as $dev)
                            <option value="{{ $dev->device_code }}" @selected($selectedDevice && $selectedDevice->id === $dev->id)>
                                {{ $dev->name }} ({{ $dev->device_code }})
                            </option>
                        @endforeach
                    </select>
                </div>
            @endif

            <div id="chart-status-badge"
                 class="inline-flex items-center gap-2 px-3 py-1.5 rounded-full text-xs font-semibold border {{ ($initialChartData['device']['is_online'] ?? false) ? 'bg-emerald-50 text-emerald-700 border-emerald-200' : 'bg-red-50 text-[#BA1A1A] border-red-200' }}">
                <span id="chart-status-dot" class="w-2 h-2 rounded-full {{ ($initialChartData['device']['is_online'] ?? false) ? 'bg-emerald-700' : 'bg-[#BA1A1A]' }}" aria-hidden="true"></span>
                <span id="chart-status-text">{{ ($initialChartData['device']['is_online'] ?? false) ? 'ESP32 Online' : 'ESP32 Offline' }}</span>
            </div>

            <span class="text-xs text-slateink bg-cobalt-50 px-3 py-1.5 rounded-full border border-cobalt-100">
                Data periode: <strong id="chart-points-count" class="text-cobalt-900 tabular-nums">{{ $initialChartData['total_points'] }}</strong> titik
            </span>
        </div>

        <!-- Period Pills Group -->
        <div class="flex flex-wrap items-center gap-2" id="period-buttons">
            @foreach ([
                '1h' => '1 Jam Terakhir',
                '6h' => '6 Jam Terakhir',
                '12h' => '12 Jam Terakhir',
                '24h' => '24 Jam Terakhir',
            ] as $key => $label)
                <button type="button"
                        data-period="{{ $key }}"
                        class="period-btn whitespace-nowrap min-h-[44px] sm:min-h-[38px] px-3 py-1.5 rounded-xl text-xs font-semibold border transition {{ $activePeriod === $key ? 'hero-cobalt-card text-white border-cobalt-800 shadow-sm' : 'bg-white text-slateink border-slate-200 hover:bg-cobalt-50 hover:text-cobalt-800' }}">
                    {{ $label }}
                </button>
            @endforeach
        </div>
    </div>

    <!-- Date Picker Row -->
    <div class="pt-3 border-t border-slate-100 flex flex-wrap items-center justify-between gap-3">
        <span class="text-xs text-slateink">Pilih tanggal spesifik untuk inspeksi historis 24 jam penuh.</span>
        <div class="flex flex-wrap items-center gap-2">
            <label for="chart-date-input" class="text-xs font-semibold text-slateink">Pilih Tanggal:</label>
            <input type="date"
                   id="chart-date-input"
                   value="{{ $selectedDate }}"
                   max="{{ now()->format('Y-m-d') }}"
                   class="min-h-[44px] sm:min-h-[38px] rounded-xl border border-slate-200 bg-white px-3 py-1.5 text-xs font-semibold text-slate-900 focus:border-cobalt-800">
            <button type="button"
                    id="apply-date-btn"
                    class="min-h-[44px] sm:min-h-[38px] px-3.5 py-1.5 rounded-xl text-xs font-semibold border transition {{ $activePeriod === 'date' ? 'hero-cobalt-card text-white border-cobalt-800' : 'bg-cobalt-50 text-cobalt-800 border-cobalt-100 hover:bg-cobalt-100' }}">
                Terapkan Tanggal
            </button>
        </div>
    </div>
</div>

<!-- Empty State Notice -->
<div id="charts-empty-banner"
     class="{{ $initialChartData['total_points'] > 0 ? 'hidden' : '' }} mb-6 rounded-2xl border border-amber-300 bg-solar-50 px-5 py-4 text-sm text-amber-900">
    <strong>Belum ada data pada periode <span id="empty-period-label">{{ $initialChartData['period_label'] }}</span>.</strong>
    Grafik akan tergambar otomatis segera setelah terdapat pembacaan sensor aktual dari ESP32 pada rentang waktu yang dipilih.
</div>

<!-- 5 Required Chart.js Panels -->
<div class="grid grid-cols-1 lg:grid-cols-2 gap-5">
    <!-- 1. Tegangan (Volt) -->
    <div class="instrument-card rounded-3xl p-5 sm:p-6 min-w-0">
        <div class="flex items-center justify-between gap-2 mb-4">
            <div>
                <h2 class="text-base font-medium text-slate-900">Grafik Tegangan terhadap Waktu</h2>
                <p class="text-xs text-slateink mt-0.5">Parameter kelistrikan INA219 (Volt)</p>
            </div>
            <span id="badge-voltage" class="px-3 py-1 rounded-full bg-cobalt-50 text-cobalt-800 border border-cobalt-100 text-xs font-bold tabular-nums shrink-0">
                {{ $initialChartData['latest'] ? number_format($initialChartData['latest']['voltage'], 2) . ' V' : '- V' }}
            </span>
        </div>
        <div class="relative h-64">
            <canvas id="chart-voltage"></canvas>
        </div>
    </div>

    <!-- 2. Arus Listrik (mA) -->
    <div class="instrument-card rounded-3xl p-5 sm:p-6 min-w-0">
        <div class="flex items-center justify-between gap-2 mb-4">
            <div>
                <h2 class="text-base font-medium text-slate-900">Grafik Arus Listrik terhadap Waktu</h2>
                <p class="text-xs text-slateink mt-0.5">Parameter beban DC INA219 (mA)</p>
            </div>
            <span id="badge-current" class="px-3 py-1 rounded-full bg-cobalt-50 text-cobalt-800 border border-cobalt-100 text-xs font-bold tabular-nums shrink-0">
                {{ $initialChartData['latest'] ? number_format($initialChartData['latest']['current'], 2) . ' mA' : '- mA' }}
            </span>
        </div>
        <div class="relative h-64">
            <canvas id="chart-current"></canvas>
        </div>
    </div>

    <!-- 3. Daya Listrik (Watt) -->
    <div class="instrument-card rounded-3xl p-5 sm:p-6 min-w-0">
        <div class="flex items-center justify-between gap-2 mb-4">
            <div>
                <h2 class="text-base font-medium text-slate-900">Grafik Daya Listrik terhadap Waktu</h2>
                <p class="text-xs text-slateink mt-0.5">Output daya panel surya (Watt)</p>
            </div>
            <span id="badge-power" class="px-3 py-1 rounded-full bg-cobalt-800 text-white text-xs font-bold tabular-nums shrink-0">
                {{ $initialChartData['latest'] ? number_format($initialChartData['latest']['power'], 3) . ' W' : '- W' }}
            </span>
        </div>
        <div class="relative h-64">
            <canvas id="chart-power"></canvas>
        </div>
    </div>

    <!-- 4. Temperatur Panel (°C) -->
    <div class="instrument-card rounded-3xl p-5 sm:p-6 min-w-0">
        <div class="flex items-center justify-between gap-2 mb-4">
            <div>
                <h2 class="text-base font-medium text-slate-900">Grafik Temperatur Panel terhadap Waktu</h2>
                <p class="text-xs text-slateink mt-0.5">Sensor DS18B20 Waterproof (&deg;C)</p>
            </div>
            <span id="badge-temperature" class="px-3 py-1 rounded-full bg-emerald-50 text-emerald-700 border border-emerald-200 text-xs font-bold tabular-nums shrink-0">
                {{ $initialChartData['latest'] ? number_format($initialChartData['latest']['temperature'], 1) . ' °C' : '- °C' }}
            </span>
        </div>
        <div class="relative h-64">
            <canvas id="chart-temperature"></canvas>
        </div>
    </div>

    <!-- 5. Intensitas Cahaya (Lux) - Full Width on Desktop -->
    <div class="lg:col-span-2 instrument-card rounded-3xl p-5 sm:p-6 min-w-0">
        <div class="flex items-center justify-between gap-2 mb-4">
            <div>
                <h2 class="text-base font-medium text-slate-900">Grafik Intensitas Cahaya terhadap Waktu</h2>
                <p class="text-xs text-slateink mt-0.5">Sensor intensitas cahaya BH1750 (Lux)</p>
            </div>
            <span id="badge-lux" class="px-3 py-1 rounded-full bg-amber-50 text-[#92400E] border border-amber-200 text-xs font-bold tabular-nums shrink-0">
                {{ $initialChartData['latest'] ? number_format($initialChartData['latest']['lux'], 0, ',', '.') . ' Lux' : '- Lux' }}
            </span>
        </div>
        <div class="relative h-72">
            <canvas id="chart-lux"></canvas>
        </div>
    </div>
</div>
