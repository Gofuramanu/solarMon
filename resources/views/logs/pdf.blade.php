<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="utf-8">
    <title>Laporan Data Monitoring Panel Surya</title>
    <style>
        body {
            font-family: DejaVu Sans, sans-serif;
            font-size: 10px;
            color: #1e293b;
            margin: 16px;
        }
        .header {
            border-bottom: 2px solid #0f172a;
            padding-bottom: 10px;
            margin-bottom: 14px;
        }
        .title {
            font-size: 16px;
            font-weight: bold;
            color: #0f172a;
            margin: 0 0 4px 0;
        }
        .subtitle {
            font-size: 10px;
            color: #475569;
            margin: 0;
        }
        .meta {
            margin-bottom: 12px;
            font-size: 9.5px;
            color: #334155;
        }
        .summary-grid {
            width: 100%;
            border-collapse: collapse;
            margin-bottom: 14px;
        }
        .summary-grid td {
            border: 1px solid #cbd5e1;
            padding: 6px 8px;
            background: #f8fafc;
            width: 25%;
        }
        .summary-label {
            font-size: 8.5px;
            color: #64748b;
            text-transform: uppercase;
        }
        .summary-value {
            font-size: 12px;
            font-weight: bold;
            color: #0f172a;
            margin-top: 2px;
        }
        table.data {
            width: 100%;
            border-collapse: collapse;
        }
        table.data th {
            background: #0f172a;
            color: #ffffff;
            text-align: left;
            padding: 6px 7px;
            font-size: 9px;
        }
        table.data td {
            border-bottom: 1px solid #e2e8f0;
            padding: 5px 7px;
            font-size: 9px;
        }
        table.data tr:nth-child(even) td {
            background: #f8fafc;
        }
        .note {
            margin-top: 10px;
            font-size: 8.5px;
            color: #64748b;
        }
    </style>
</head>
<body>
    <div class="header">
        <p class="title">{{ $siteName }} - Laporan Pengukuran Sensor</p>
        <p class="subtitle">Sistem Monitoring Parameter Kelistrikan, Intensitas Cahaya, dan Temperatur Panel Surya Berbasis ESP32</p>
    </div>

    <div class="meta">
        <strong>Perangkat:</strong> {{ $selectedDevice ? $selectedDevice->name . ' (' . $selectedDevice->device_code . ')' : 'Semua Perangkat' }}
        &nbsp;|&nbsp;
        <strong>Rentang Tanggal:</strong> {{ $dateFrom ?: 'Awal' }} s/d {{ $dateTo ?: 'Terbaru' }}
        @if ($search)
            &nbsp;|&nbsp; <strong>Pencarian:</strong> "{{ $search }}"
        @endif
        &nbsp;|&nbsp;
        <strong>Dicetak:</strong> {{ $generatedAt }}
    </div>

    <table class="summary-grid">
        <tr>
            <td>
                <div class="summary-label">Total Record Filter</div>
                <div class="summary-value">{{ number_format($totalMatching, 0, ',', '.') }} data</div>
            </td>
            <td>
                <div class="summary-label">Energi Terakumulasi</div>
                <div class="summary-value">{{ number_format($analysis['energy_wh'], 4) }} Wh ({{ number_format($analysis['energy_kwh'], 5) }} kWh)</div>
            </td>
            <td>
                <div class="summary-label">Rata-rata / Maks Daya</div>
                <div class="summary-value">
                    {{ $analysis['metrics']['power']['avg'] !== null ? number_format($analysis['metrics']['power']['avg'], 3) . ' W' : '-' }}
                    /
                    {{ $analysis['metrics']['power']['max'] !== null ? number_format($analysis['metrics']['power']['max'], 3) . ' W' : '-' }}
                </div>
            </td>
            <td>
                <div class="summary-label">Rata-rata Suhu / Lux</div>
                <div class="summary-value">
                    {{ $analysis['metrics']['temperature']['avg'] !== null ? number_format($analysis['metrics']['temperature']['avg'], 1) . ' °C' : '-' }}
                    /
                    {{ $analysis['metrics']['lux']['avg'] !== null ? number_format($analysis['metrics']['lux']['avg'], 0, ',', '.') . ' Lux' : '-' }}
                </div>
            </td>
        </tr>
    </table>

    <table class="data">
        <thead>
            <tr>
                <th>ID</th>
                <th>Perangkat</th>
                <th>Tegangan (V)</th>
                <th>Arus (mA)</th>
                <th>Daya (W)</th>
                <th>Temperatur (&deg;C)</th>
                <th>Intensitas Cahaya (Lux)</th>
                <th>Timestamp</th>
            </tr>
        </thead>
        <tbody>
            @forelse ($rows as $row)
                <tr>
                    <td>#{{ $row->id }}</td>
                    <td>{{ $row->device?->device_code ?? '-' }}</td>
                    <td>{{ number_format((float) $row->voltage, 3) }}</td>
                    <td>{{ number_format((float) $row->current, 2) }}</td>
                    <td>{{ number_format((float) $row->power, 4) }}</td>
                    <td>{{ number_format((float) $row->temperature, 2) }}</td>
                    <td>{{ number_format((float) $row->lux, 0, ',', '.') }}</td>
                    <td>{{ $row->recorded_at->format('Y-m-d H:i:s') }}</td>
                </tr>
            @empty
                <tr>
                    <td colspan="8" style="text-align: center;">Tidak ada data pengukuran pada filter ini.</td>
                </tr>
            @endforelse
        </tbody>
    </table>

    @if ($totalMatching > $maxPdfRows)
        <p class="note">
            * Menampilkan {{ $maxPdfRows }} baris pertama dari {{ number_format($totalMatching, 0, ',', '.') }} total data. Gunakan Export CSV untuk mengunduh seluruh baris tanpa batas.
        </p>
    @endif
</body>
</html>
