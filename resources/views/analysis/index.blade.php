@extends('layouts.app')

@section('title', 'Analisis Data | ' . $siteName)
@section('page_title', 'Analisis Data & Energi Listrik')
@section('page_subtitle', 'Statistik rata-rata, maksimum, minimum, dan total akumulasi energi listrik (Wh / kWh) berbasis integrasi Trapezoidal.')

@section('page_actions')
    <div class="flex flex-wrap items-center gap-2 shrink-0">
        <a href="{{ route('logs.export.pdf', ['device' => $selectedDevice?->device_code]) }}"
           class="inline-flex items-center justify-center gap-1.5 px-4 min-h-[44px] sm:min-h-[40px] rounded-xl hero-cobalt-card text-white text-xs font-semibold shadow-sm hover:opacity-95 transition">
            <span>Unduh Laporan PDF</span>
        </a>
    </div>
@endsection

@section('content')
    @php
        $avgPower = $analysis['metrics']['power']['avg'] ?? null;
        $maxPower = $analysis['metrics']['power']['max'] ?? null;
        $avgTemp = $analysis['metrics']['temperature']['avg'] ?? null;
        $maxTemp = $analysis['metrics']['temperature']['max'] ?? null;
        $avgLux = $analysis['metrics']['lux']['avg'] ?? null;
        $maxLux = $analysis['metrics']['lux']['max'] ?? null;
    @endphp

    <!-- Filter Bar (Matches Desktop 5240 & Mobile 3833 Pill Navigation) -->
    <div class="instrument-card rounded-3xl p-4 sm:p-5 mb-6">
        <form method="GET" action="{{ route('analysis.index') }}" class="flex flex-col lg:flex-row lg:items-end justify-between gap-4">
            <div class="flex flex-wrap items-end gap-3 min-w-0">
                @if ($devices->isNotEmpty())
                    <div class="w-full sm:w-auto min-w-0">
                        <label for="analysis-device" class="block text-xs font-semibold text-slateink mb-1">Perangkat</label>
                        <select id="analysis-device" name="device"
                                class="w-full sm:w-auto max-w-full min-w-0 min-h-[44px] sm:min-h-[40px] rounded-xl border border-slate-200 bg-canvas px-3.5 py-2 text-sm font-semibold text-slate-900 focus:border-cobalt-800">
                            @foreach ($devices as $dev)
                                <option value="{{ $dev->device_code }}" @selected($selectedDevice && $selectedDevice->id === $dev->id)>
                                    {{ $dev->name }} ({{ $dev->device_code }})
                                </option>
                            @endforeach
                        </select>
                    </div>
                @endif

                <div class="w-full sm:w-auto min-w-0">
                    <label for="analysis-range" class="block text-xs font-semibold text-slateink mb-1">Periode Analisis</label>
                    <select id="analysis-range" name="range"
                            onchange="toggleCustomRange(this.value)"
                            class="w-full sm:w-auto max-w-full min-w-0 min-h-[44px] sm:min-h-[40px] rounded-xl border border-slate-200 bg-canvas px-3.5 py-2 text-sm font-semibold text-slate-900 focus:border-cobalt-800">
                        <option value="1h" @selected($range === '1h')>1 Jam Terakhir</option>
                        <option value="6h" @selected($range === '6h')>6 Jam Terakhir</option>
                        <option value="12h" @selected($range === '12h')>12 Jam Terakhir</option>
                        <option value="24h" @selected($range === '24h')>24 Jam Terakhir</option>
                        <option value="7d" @selected($range === '7d')>7 Hari Terakhir</option>
                        <option value="all" @selected($range === 'all')>Seluruh Data</option>
                        <option value="custom" @selected($range === 'custom')>Rentang Tanggal Kustom</option>
                    </select>
                </div>

                <div id="custom-date-inputs" class="{{ $range === 'custom' ? 'flex' : 'hidden' }} flex-wrap items-end gap-2">
                    <div>
                        <label for="analysis-date-from" class="block text-xs font-semibold text-slateink mb-1">Dari</label>
                        <input id="analysis-date-from" type="date" name="date_from" value="{{ $dateFrom }}"
                               class="min-h-[44px] sm:min-h-[40px] rounded-xl border border-slate-200 px-3 py-2 text-sm text-slate-900 focus:border-cobalt-800">
                    </div>
                    <div>
                        <label for="analysis-date-to" class="block text-xs font-semibold text-slateink mb-1">Sampai</label>
                        <input id="analysis-date-to" type="date" name="date_to" value="{{ $dateTo }}"
                               class="min-h-[44px] sm:min-h-[40px] rounded-xl border border-slate-200 px-3 py-2 text-sm text-slate-900 focus:border-cobalt-800">
                    </div>
                </div>

                <button type="submit"
                        class="min-h-[44px] sm:min-h-[40px] px-4 py-2 rounded-xl hero-cobalt-card text-white text-sm font-semibold shadow-sm hover:opacity-95 transition">
                    Hitung Analisis
                </button>
            </div>

            <div class="text-xs text-slateink bg-cobalt-50 px-3.5 py-2.5 rounded-xl border border-cobalt-100">
                Periode aktif: <strong class="text-cobalt-900">{{ $rangeLabel }}</strong>
                &bull; Sampel: <strong class="text-cobalt-900 tabular-nums">{{ number_format($analysis['total_records'], 0, ',', '.') }}</strong>
            </div>
        </form>
    </div>

    <!-- Top 4-Card Analytical Strip (Matches FigCraft Desktop 5240 Top Row) -->
    <div class="grid grid-cols-1 sm:grid-cols-2 xl:grid-cols-4 gap-5 mb-6">
        <!-- Card 1: Solid Cobalt Energi Hero -->
        <div class="hero-cobalt-card rounded-3xl p-5 sm:p-6 text-white shadow-[0_12px_28px_-6px_rgba(30,64,175,0.32)] flex flex-col justify-between">
            <div class="flex items-start justify-between gap-2">
                <div class="text-xs font-semibold text-cobalt-100 uppercase tracking-wider">Energi Terakumulasi</div>
                <span class="px-2 py-0.5 rounded-full bg-white/15 text-[11px] font-semibold">Wh / kWh</span>
            </div>
            <div class="my-4">
                <div class="text-3xl sm:text-4xl font-light tracking-tight tabular-nums leading-none">
                    {{ number_format($analysis['energy_wh'], 4) }} <span class="text-base font-medium text-cobalt-100">Wh</span>
                </div>
                <div class="mt-1.5 text-xs font-medium text-cobalt-100 tabular-nums">
                    {{ number_format($analysis['energy_kwh'], 5) }} kWh
                </div>
            </div>
            <div class="pt-3 border-t border-white/15 text-[11px] text-cobalt-100 flex items-center justify-between">
                <span>Durasi: {{ gmdate('H:i:s', $analysis['integrated_seconds']) }}</span>
                <span>{{ $analysis['valid_intervals'] }} interval</span>
            </div>
        </div>

        <!-- Card 2: Puncak & Rata-Rata Daya -->
        <div class="instrument-card rounded-3xl p-5 sm:p-6 flex flex-col justify-between">
            <div class="flex items-start justify-between gap-2">
                <div class="text-xs font-semibold text-slateink">Puncak Output Daya</div>
                <span class="w-8 h-8 rounded-full bg-cobalt-50 text-cobalt-800 flex items-center justify-center text-xs font-bold" aria-hidden="true">W</span>
            </div>
            <div class="my-4 flex items-baseline gap-1.5">
                <span class="text-3xl font-light text-slate-900 tracking-tight tabular-nums leading-none">
                    {{ $maxPower !== null ? number_format($maxPower, 3) : '-' }}
                </span>
                <span class="text-sm font-medium text-slateink">Watt</span>
            </div>
            <div class="text-xs text-slateink flex items-center justify-between">
                <span>Rata-rata: <strong class="text-cobalt-800 tabular-nums">{{ $avgPower !== null ? number_format($avgPower, 3) . ' W' : '-' }}</strong></span>
                <span class="px-2 py-0.5 rounded-full bg-cobalt-50 text-cobalt-800 font-semibold">INA219</span>
            </div>
        </div>

        <!-- Card 3: Puncak Radiasi Matahari (Lux) -->
        <div class="instrument-card rounded-3xl p-5 sm:p-6 flex flex-col justify-between">
            <div class="flex items-start justify-between gap-2">
                <div class="text-xs font-semibold text-slateink">Puncak Intensitas Cahaya</div>
                <span class="w-8 h-8 rounded-full bg-cobalt-50 text-cobalt-800 flex items-center justify-center text-xs font-bold" aria-hidden="true">Lx</span>
            </div>
            <div class="my-4 flex items-baseline gap-1.5">
                <span class="text-3xl font-light text-slate-900 tracking-tight tabular-nums leading-none">
                    {{ $maxLux !== null ? number_format($maxLux, 0, ',', '.') : '-' }}
                </span>
                <span class="text-sm font-medium text-slateink">Lux</span>
            </div>
            <div class="text-xs text-slateink flex items-center justify-between">
                <span>Rata-rata: <strong class="text-slate-900 tabular-nums">{{ $avgLux !== null ? number_format($avgLux, 0, ',', '.') . ' Lux' : '-' }}</strong></span>
                <span class="px-2 py-0.5 rounded-full bg-cobalt-50 text-cobalt-800 font-semibold">BH1750</span>
            </div>
        </div>

        <!-- Card 4: Analisis Termal Panel (DS18B20) -->
        <div class="instrument-card rounded-3xl p-5 sm:p-6 flex flex-col justify-between">
            <div class="flex items-start justify-between gap-2">
                <div class="text-xs font-semibold text-slateink">Puncak Suhu Panel</div>
                <span class="w-8 h-8 rounded-full bg-cobalt-50 text-cobalt-800 flex items-center justify-center text-xs font-bold" aria-hidden="true">&deg;C</span>
            </div>
            <div class="my-4 flex items-baseline gap-1">
                <span class="text-3xl font-light text-slate-900 tracking-tight tabular-nums leading-none">
                    {{ $maxTemp !== null ? number_format($maxTemp, 1) : '-' }}
                </span>
                <span class="text-sm font-medium text-slateink">&deg;C</span>
            </div>
            <div class="text-xs text-slateink flex items-center justify-between">
                <span>Rata-rata: <strong class="text-slate-900 tabular-nums">{{ $avgTemp !== null ? number_format($avgTemp, 1) . ' °C' : '-' }}</strong></span>
                <span class="px-2 py-0.5 rounded-full font-semibold {{ ($maxTemp ?? 0) >= 55 ? 'bg-red-50 text-[#BA1A1A]' : 'bg-emerald-50 text-emerald-700' }}">
                    {{ $maxTemp === null ? 'Menunggu' : (($maxTemp >= 55) ? 'Tinggi' : 'Aman < 55°C') }}
                </span>
            </div>
        </div>
    </div>

    <!-- Formula & Trapezoidal Transparency Banner -->
    <div class="instrument-card rounded-3xl p-5 sm:p-6 mb-6 flex flex-col lg:flex-row lg:items-center lg:justify-between gap-4">
        <div class="space-y-1">
            <h2 class="text-base font-medium text-slate-900">Metode Integrasi Energi Listrik (Trapezoidal Rule)</h2>
            <p class="text-xs sm:text-sm text-slateink leading-relaxed">
                Dihitung melalui integrasi numerik daya terhadap selisih waktu antar pembacaan berurutan
                (<code class="px-1.5 py-0.5 rounded bg-cobalt-50 text-cobalt-900 font-mono text-xs">&Sigma; ((P_i + P_{i+1}) / 2) &times; &Delta;t / 3600</code>),
                bukan sekadar menjumlahkan daya sesaat. Jeda antar kiriman &gt; {{ $analysis['max_gap_seconds'] }} detik otomatis diabaikan agar waktu offline tidak dihitung.
            </p>
        </div>
        <div class="shrink-0 rounded-2xl bg-cobalt-50 border border-cobalt-100 px-4 py-3 text-xs text-cobalt-900 space-y-1 tabular-nums">
            <div>Interval valid: <strong>{{ $analysis['valid_intervals'] }}</strong></div>
            <div>Jeda offline dilewati: <strong>{{ $analysis['skipped_gaps'] }}</strong></div>
        </div>
    </div>

    <!-- 5 Parameter Detailed Statistical Cards (AVG, MAX, MIN) -->
    @php
        $statCards = [
            ['key' => 'voltage',     'title' => 'Tegangan Panel Surya', 'unit' => 'V',    'dec' => 3, 'accent' => 'text-cobalt-800 bg-cobalt-50 border border-cobalt-100'],
            ['key' => 'current',     'title' => 'Arus Listrik',         'unit' => 'mA',   'dec' => 2, 'accent' => 'text-cobalt-800 bg-cobalt-50 border border-cobalt-100'],
            ['key' => 'power',       'title' => 'Daya Listrik',         'unit' => 'Watt', 'dec' => 4, 'accent' => 'text-white hero-cobalt-card'],
            ['key' => 'temperature', 'title' => 'Temperatur Panel',     'unit' => '°C',   'dec' => 2, 'accent' => 'text-emerald-700 bg-emerald-50 border border-emerald-200'],
            ['key' => 'lux',         'title' => 'Intensitas Cahaya',    'unit' => 'Lux',  'dec' => 0, 'accent' => 'text-[#92400E] bg-amber-50 border border-amber-200'],
        ];
    @endphp

    <div class="grid grid-cols-1 md:grid-cols-2 xl:grid-cols-3 gap-5">
        @foreach ($statCards as $card)
            @php $m = $analysis['metrics'][$card['key']]; @endphp
            <div class="instrument-card rounded-3xl p-5 sm:p-6">
                <div class="flex items-center justify-between mb-4">
                    <h3 class="text-base font-medium text-slate-900">{{ $card['title'] }}</h3>
                    <span class="px-2.5 py-1 rounded-full text-xs font-bold {{ $card['accent'] }}">{{ $card['unit'] }}</span>
                </div>

                <div class="grid grid-cols-3 gap-2.5 text-center">
                    <div class="rounded-2xl bg-canvas border border-slate-200/80 p-3">
                        <div class="text-[11px] font-semibold uppercase text-slateink">Rata-rata</div>
                        <div class="mt-1 text-base font-semibold text-slate-900 tabular-nums">
                            {{ $m['avg'] !== null ? number_format($m['avg'], $card['dec']) : '-' }}
                        </div>
                    </div>
                    <div class="rounded-2xl bg-cobalt-50 border border-cobalt-100 p-3">
                        <div class="text-[11px] font-semibold uppercase text-cobalt-800">Maksimum</div>
                        <div class="mt-1 text-base font-semibold text-cobalt-900 tabular-nums">
                            {{ $m['max'] !== null ? number_format($m['max'], $card['dec']) : '-' }}
                        </div>
                    </div>
                    <div class="rounded-2xl bg-slate-50 border border-slate-200 p-3">
                        <div class="text-[11px] font-semibold uppercase text-slateink">Minimum</div>
                        <div class="mt-1 text-base font-semibold text-slate-900 tabular-nums">
                            {{ $m['min'] !== null ? number_format($m['min'], $card['dec']) : '-' }}
                        </div>
                    </div>
                </div>
            </div>
        @endforeach
    </div>
@endsection

@push('scripts')
<script>
    function toggleCustomRange(val) {
        const box = document.getElementById('custom-date-inputs');
        if (!box) return;
        if (val === 'custom') {
            box.classList.remove('hidden');
            box.classList.add('flex');
        } else {
            box.classList.add('hidden');
            box.classList.remove('flex');
        }
    }
</script>
@endpush
