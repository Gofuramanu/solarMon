@extends('layouts.app')

@section('title', 'Data Logging | ' . $siteName)
@section('page_title', 'Data Logging & Riwayat Pengukuran')
@section('page_subtitle', 'Pencatatan data multi-parameter sensor ESP32 secara kontinu pada database MySQL melalui HTTP REST API.')

@section('page_actions')
    <div class="flex flex-wrap items-center gap-2.5 shrink-0">
        <a href="{{ route('logs.export.csv', request()->query()) }}"
           class="inline-flex items-center justify-center gap-1.5 min-h-[44px] sm:min-h-[40px] px-4 py-2 rounded-xl bg-white hover:bg-slate-50 text-slate-900 border border-slate-200 text-xs font-semibold shadow-sm transition">
            <span>Ekspor CSV</span>
        </a>
        <a href="{{ route('logs.export.pdf', request()->query()) }}"
           class="inline-flex items-center justify-center gap-1.5 min-h-[44px] sm:min-h-[40px] px-4 py-2 rounded-xl hero-cobalt-card hover:opacity-95 text-white text-xs font-semibold shadow-sm transition">
            <span>Unduh Laporan PDF</span>
        </a>
    </div>
@endsection

@section('content')
    @php
        $avgPower = $summaryAnalysis['metrics']['power']['avg'] ?? null;
        $avgVoltage = $summaryAnalysis['metrics']['voltage']['avg'] ?? null;
    @endphp

    <!-- Top 3-Card Telemetry Summary Strip (Matches FigCraft Desktop 5838) -->
    <div class="grid grid-cols-1 md:grid-cols-3 gap-5 mb-6">
        <div class="instrument-card rounded-3xl p-5 sm:p-6 flex flex-col justify-between">
            <div class="flex items-start justify-between gap-2">
                <div>
                    <div class="text-xs font-semibold text-slateink">Penyimpanan MySQL</div>
                    <div class="text-[11px] text-slate-600 mt-0.5">Tabel sensor_readings</div>
                </div>
                <span class="px-2.5 py-1 rounded-full bg-cobalt-50 text-cobalt-800 text-[11px] font-semibold">Aktif</span>
            </div>
            <div class="my-3 flex items-baseline gap-2">
                <span class="text-3xl font-light text-slate-900 tabular-nums leading-none">
                    {{ number_format($readings->total(), 0, ',', '.') }}
                </span>
                <span class="text-xs font-semibold text-slateink">baris terfilter</span>
            </div>
            <div class="text-xs text-slateink">
                Halaman {{ $readings->currentPage() }} dari {{ max(1, $readings->lastPage()) }}
            </div>
        </div>

        <div class="instrument-card rounded-3xl p-5 sm:p-6 flex flex-col justify-between">
            <div class="flex items-start justify-between gap-2">
                <div>
                    <div class="text-xs font-semibold text-slateink">Energi Hasil Filter</div>
                    <div class="text-[11px] text-slate-600 mt-0.5">Integrasi Trapezoidal</div>
                </div>
                <span class="px-2.5 py-1 rounded-full bg-cobalt-50 text-cobalt-800 text-[11px] font-semibold">Wh / kWh</span>
            </div>
            <div class="my-3">
                <span class="text-3xl font-light text-slate-900 tabular-nums leading-none">
                    {{ number_format($summaryAnalysis['energy_wh'] ?? 0, 4) }}
                </span>
                <span class="text-xs font-semibold text-slateink ml-1">Wh</span>
            </div>
            <div class="text-xs text-slateink tabular-nums">
                Rata-rata Daya: <strong class="text-cobalt-800">{{ $avgPower !== null ? number_format($avgPower, 3) . ' W' : '-' }}</strong>
            </div>
        </div>

        <div class="hero-cobalt-card rounded-3xl p-5 sm:p-6 text-white shadow-[0_12px_28px_-6px_rgba(30,64,175,0.28)] flex flex-col justify-between">
            <div class="flex items-start justify-between gap-2">
                <div class="text-xs font-semibold uppercase tracking-wider text-cobalt-100">Status Transmisi</div>
                <span class="px-2.5 py-0.5 rounded-full bg-white/15 text-[11px] font-semibold">HTTP REST</span>
            </div>
            <div class="my-3 flex items-baseline gap-2">
                <span class="text-3xl font-light text-white tabular-nums leading-none">
                    {{ $avgVoltage !== null ? number_format($avgVoltage, 2) : '-' }}
                </span>
                <span class="text-xs font-medium text-cobalt-100">V rata-rata</span>
            </div>
            <div class="text-xs text-cobalt-100">
                Perangkat: <strong class="text-white">{{ $selectedDevice ? $selectedDevice->device_code : 'Semua Perangkat' }}</strong>
            </div>
        </div>
    </div>

    <!-- Filter & Search Toolbar (Rounded-3xl) -->
    <div class="instrument-card rounded-3xl p-5 mb-6 space-y-4">
        <form method="GET" action="{{ route('logs.index') }}" class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-12 gap-3 items-end">
            <div class="lg:col-span-3">
                <label for="search" class="block text-xs font-semibold text-slateink mb-1">Pencarian</label>
                <input type="text" id="search" name="search" value="{{ $search }}"
                       placeholder="Cari ID, perangkat, nilai, atau tanggal..."
                       class="w-full min-h-[44px] sm:min-h-[40px] rounded-xl border border-slate-200 bg-canvas px-3.5 py-2 text-sm text-slate-900 focus:border-cobalt-800">
            </div>

            <div class="lg:col-span-2">
                <label for="device" class="block text-xs font-semibold text-slateink mb-1">Perangkat</label>
                <select id="device" name="device"
                        class="w-full min-h-[44px] sm:min-h-[40px] rounded-xl border border-slate-200 bg-canvas px-3 py-2 text-sm font-medium text-slate-900 focus:border-cobalt-800">
                    <option value="all">Semua Perangkat</option>
                    @foreach ($devices as $dev)
                        <option value="{{ $dev->device_code }}" @selected($selectedDevice && $selectedDevice->id === $dev->id)>
                            {{ $dev->device_code }}
                        </option>
                    @endforeach
                </select>
            </div>

            <div class="lg:col-span-2">
                <label for="date_from" class="block text-xs font-semibold text-slateink mb-1">Dari Tanggal</label>
                <input type="date" id="date_from" name="date_from" value="{{ $dateFrom }}"
                       class="w-full min-h-[44px] sm:min-h-[40px] rounded-xl border border-slate-200 bg-canvas px-3 py-2 text-sm text-slate-900 focus:border-cobalt-800">
            </div>

            <div class="lg:col-span-2">
                <label for="date_to" class="block text-xs font-semibold text-slateink mb-1">Sampai Tanggal</label>
                <input type="date" id="date_to" name="date_to" value="{{ $dateTo }}"
                       class="w-full min-h-[44px] sm:min-h-[40px] rounded-xl border border-slate-200 bg-canvas px-3 py-2 text-sm text-slate-900 focus:border-cobalt-800">
            </div>

            <div class="lg:col-span-1">
                <label for="per_page" class="block text-xs font-semibold text-slateink mb-1">Baris</label>
                <select id="per_page" name="per_page"
                        class="w-full min-h-[44px] sm:min-h-[40px] rounded-xl border border-slate-200 bg-canvas px-2.5 py-2 text-sm font-medium text-slate-900 focus:border-cobalt-800">
                    @foreach ([15, 25, 50, 100] as $size)
                        <option value="{{ $size }}" @selected($perPage === $size)>{{ $size }}</option>
                    @endforeach
                </select>
            </div>

            <input type="hidden" name="sort" value="{{ $sort }}">
            <input type="hidden" name="direction" value="{{ $direction }}">

            <div class="lg:col-span-2 flex items-center gap-2">
                <button type="submit"
                        class="flex-1 min-h-[44px] sm:min-h-[40px] px-4 py-2 rounded-xl hero-cobalt-card text-white text-sm font-semibold shadow-sm hover:opacity-95 transition">
                    Filter
                </button>
                <a href="{{ route('logs.index') }}"
                   class="inline-flex items-center justify-center min-h-[44px] sm:min-h-[40px] px-3 py-2 rounded-xl border border-slate-200 text-slateink hover:bg-slate-50 text-sm font-medium transition">
                    Reset
                </a>
            </div>
        </form>

        <div class="pt-3 border-t border-slate-100 flex flex-wrap items-center justify-between gap-2 text-xs text-slateink">
            <span>
                Menampilkan <strong class="text-slate-900">{{ $readings->firstItem() ?? 0 }}</strong> s/d
                <strong class="text-slate-900">{{ $readings->lastItem() ?? 0 }}</strong> dari
                <strong class="text-slate-900">{{ number_format($readings->total(), 0, ',', '.') }}</strong> record tersimpan
            </span>
            <span class="px-2.5 py-1 rounded-full bg-cobalt-50 text-cobalt-800 font-semibold">Sinkron MySQL</span>
        </div>
    </div>

    <!-- Sortable Data Table with Condition Status Pills (Matches FigCraft Desktop 5838) -->
    <div class="instrument-card rounded-3xl overflow-hidden">
        <div class="overflow-x-auto">
            <table class="w-full text-left border-collapse">
                <thead>
                    <tr class="bg-canvas border-b border-slate-200 text-[11px] font-semibold uppercase tracking-wider text-slateink">
                        @php
                            $columns = [
                                'id' => 'ID',
                                'device' => 'Perangkat',
                                'voltage' => 'Tegangan (V)',
                                'current' => 'Arus (mA)',
                                'power' => 'Daya (W)',
                                'lux' => 'Radiasi (Lux)',
                                'temperature' => 'Suhu (°C)',
                                'status' => 'Status Kondisi',
                                'recorded_at' => 'Timestamp',
                            ];
                        @endphp
                        @foreach ($columns as $colKey => $colLabel)
                            <th class="px-4 py-3.5 whitespace-nowrap">
                                @if (in_array($colKey, ['device', 'status'], true))
                                    {{ $colLabel }}
                                @else
                                    @php
                                        $nextDir = ($sort === $colKey && $direction === 'desc') ? 'asc' : 'desc';
                                        $sortUrl = route('logs.index', array_merge(request()->query(), [
                                            'sort' => $colKey,
                                            'direction' => $nextDir,
                                        ]));
                                    @endphp
                                    <a href="{{ $sortUrl }}" class="inline-flex items-center gap-1 hover:text-cobalt-800">
                                        <span>{{ $colLabel }}</span>
                                        @if ($sort === $colKey)
                                            <span class="text-cobalt-800">{{ $direction === 'asc' ? '▲' : '▼' }}</span>
                                        @endif
                                    </a>
                                @endif
                            </th>
                        @endforeach
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-100 text-sm">
                    @forelse ($readings as $row)
                        @php
                            $rowHighTemp = $row->temperature >= 55;
                            $rowLowLux = $row->lux < 1000;
                        @endphp
                        <tr class="hover:bg-canvas/80 transition">
                            <td class="px-4 py-3.5 font-mono text-xs text-slateink">#{{ $row->id }}</td>
                            <td class="px-4 py-3.5">
                                <span class="px-2 py-0.5 rounded-md bg-cobalt-50 text-cobalt-900 font-mono text-xs font-semibold">
                                    {{ $row->device?->device_code ?? '-' }}
                                </span>
                            </td>
                            <td class="px-4 py-3.5 font-semibold text-cobalt-800 tabular-nums whitespace-nowrap">
                                {{ number_format($row->voltage, 2) }} V
                            </td>
                            <td class="px-4 py-3.5 text-slate-900 tabular-nums whitespace-nowrap">
                                {{ number_format($row->current, 2) }} mA
                            </td>
                            <td class="px-4 py-3.5 font-bold text-slate-900 tabular-nums whitespace-nowrap">
                                {{ number_format($row->power, 3) }} W
                            </td>
                            <td class="px-4 py-3.5 text-slate-900 tabular-nums whitespace-nowrap">
                                {{ number_format($row->lux, 0, ',', '.') }} Lux
                            </td>
                            <td class="px-4 py-3.5 tabular-nums whitespace-nowrap {{ $rowHighTemp ? 'text-[#BA1A1A] font-semibold' : 'text-emerald-700 font-medium' }}">
                                {{ number_format($row->temperature, 1) }} &deg;C
                            </td>
                            <td class="px-4 py-3.5 whitespace-nowrap">
                                @if ($rowHighTemp)
                                    <span class="inline-flex items-center gap-1.5 px-2.5 py-1 rounded-full bg-red-50 text-[#BA1A1A] text-xs font-semibold">
                                        <span class="w-1.5 h-1.5 rounded-full bg-[#BA1A1A]" aria-hidden="true"></span>
                                        Suhu Tinggi
                                    </span>
                                @elseif ($rowLowLux)
                                    <span class="inline-flex items-center gap-1.5 px-2.5 py-1 rounded-full bg-amber-50 text-[#92400E] text-xs font-semibold">
                                        <span class="w-1.5 h-1.5 rounded-full bg-[#92400E]" aria-hidden="true"></span>
                                        Lux Redup
                                    </span>
                                @else
                                    <span class="inline-flex items-center gap-1.5 px-2.5 py-1 rounded-full bg-cobalt-50 text-cobalt-800 text-xs font-semibold">
                                        <span class="w-1.5 h-1.5 rounded-full bg-cobalt-800" aria-hidden="true"></span>
                                        Optimal
                                    </span>
                                @endif
                            </td>
                            <td class="px-4 py-3.5 text-xs text-slateink whitespace-nowrap tabular-nums">
                                {{ $row->recorded_at->format('d M Y, H:i:s') }}
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="9" class="px-4 py-10 text-center text-sm text-slateink">
                                Tidak ada data sensor yang cocok dengan filter saat ini.
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        @if ($readings->hasPages())
            <div class="px-5 py-4 border-t border-slate-100 bg-canvas">
                {{ $readings->links() }}
            </div>
        @endif
    </div>
@endsection
