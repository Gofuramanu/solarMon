@extends('layouts.app')

@section('title', 'Pengaturan | ' . $siteName)
@section('page_title', 'Pengaturan & Konfigurasi Sistem')
@section('page_subtitle', 'Konfigurasi parameter polling monitoring, ambang batas offline, akses publik, akun Admin, dan reset data uji.')

@section('content')
    @if (session('status'))
        <div class="mb-5 rounded-2xl bg-emerald-50 border border-emerald-200 px-5 py-3.5 text-sm text-emerald-800">
            {{ session('status') }}
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

    <!-- Top 4-Card Summary Strip (Matches FigCraft Desktop 6452) -->
    <div class="grid grid-cols-1 sm:grid-cols-2 xl:grid-cols-4 gap-4 mb-6">
        <div class="instrument-card rounded-3xl p-4 sm:p-5">
            <div class="text-[11px] font-semibold uppercase tracking-wider text-slateink">Refresh Dashboard</div>
            <div class="mt-1.5 text-2xl font-light text-slate-900 tabular-nums">{{ $refreshInterval }} <span class="text-xs font-semibold text-slateink">detik</span></div>
            <div class="mt-1 text-xs text-cobalt-800 font-medium">Auto-Polling AJAX</div>
        </div>
        <div class="instrument-card rounded-3xl p-4 sm:p-5">
            <div class="text-[11px] font-semibold uppercase tracking-wider text-slateink">Timeout Offline Default</div>
            <div class="mt-1.5 text-2xl font-light text-slate-900 tabular-nums">{{ $offlineThreshold }} <span class="text-xs font-semibold text-slateink">detik</span></div>
            <div class="mt-1 text-xs text-slateink">Batas deteksi ESP32</div>
        </div>
        <div class="instrument-card rounded-3xl p-4 sm:p-5">
            <div class="text-[11px] font-semibold uppercase tracking-wider text-slateink">Max Gap Energi</div>
            <div class="mt-1.5 text-2xl font-light text-slate-900 tabular-nums">{{ $energyMaxGap }} <span class="text-xs font-semibold text-slateink">detik</span></div>
            <div class="mt-1 text-xs text-slateink">Integrasi Trapezoidal</div>
        </div>
        <div class="instrument-card rounded-3xl p-4 sm:p-5">
            <div class="text-[11px] font-semibold uppercase tracking-wider text-slateink">Mode Akses Portal</div>
            <div class="mt-1.5 text-lg font-medium text-cobalt-800">{{ $publicDashboardEnabled ? 'Publik Read-Only' : 'Privat (Admin Only)' }}</div>
            <div class="mt-1 text-xs text-slateink tabular-nums">{{ number_format($totalReadingsCount, 0, ',', '.') }} data tersimpan</div>
        </div>
    </div>

    <div class="grid grid-cols-1 lg:grid-cols-12 gap-6">
        <!-- System Configuration -->
        <div class="lg:col-span-7 instrument-card rounded-3xl p-6">
            <h2 class="text-lg font-medium text-slate-900 mb-1">Konfigurasi Operasional Monitoring</h2>
            <p class="text-xs text-slateink mb-5">Atur interval pembaruan UI, batas offline perangkat, perhitungan energi, dan mode akses publik.</p>

            <form method="POST" action="{{ route('settings.system') }}" class="space-y-4">
                @csrf
                @method('PUT')

                <div>
                    <label for="site_name" class="block text-xs font-semibold text-slateink mb-1">Nama Aplikasi</label>
                    <input type="text" id="site_name" name="site_name" value="{{ old('site_name', $siteName) }}" required
                           class="w-full min-h-[44px] sm:min-h-[40px] rounded-xl border border-slate-200 bg-canvas px-3.5 py-2 text-sm text-slate-900 focus:border-cobalt-800">
                </div>

                <div class="grid grid-cols-1 sm:grid-cols-3 gap-4">
                    <div>
                        <label for="dashboard_refresh_interval" class="block text-xs font-semibold text-slateink mb-1">Refresh Dashboard (detik)</label>
                        <input type="number" id="dashboard_refresh_interval" name="dashboard_refresh_interval"
                               value="{{ old('dashboard_refresh_interval', $refreshInterval) }}" min="2" max="300" required
                               class="w-full min-h-[44px] sm:min-h-[40px] rounded-xl border border-slate-200 bg-canvas px-3.5 py-2 text-sm text-slate-900 focus:border-cobalt-800">
                    </div>

                    <div>
                        <label for="default_offline_threshold" class="block text-xs font-semibold text-slateink mb-1">Batas Offline Default (detik)</label>
                        <input type="number" id="default_offline_threshold" name="default_offline_threshold"
                               value="{{ old('default_offline_threshold', $offlineThreshold) }}" min="10" max="3600" required
                               class="w-full min-h-[44px] sm:min-h-[40px] rounded-xl border border-slate-200 bg-canvas px-3.5 py-2 text-sm text-slate-900 focus:border-cobalt-800">
                    </div>

                    <div>
                        <label for="energy_max_gap_seconds" class="block text-xs font-semibold text-slateink mb-1">Max Gap Energi (detik)</label>
                        <input type="number" id="energy_max_gap_seconds" name="energy_max_gap_seconds"
                               value="{{ old('energy_max_gap_seconds', $energyMaxGap) }}" min="10" max="3600" required
                               class="w-full min-h-[44px] sm:min-h-[40px] rounded-xl border border-slate-200 bg-canvas px-3.5 py-2 text-sm text-slate-900 focus:border-cobalt-800">
                    </div>
                </div>

                <div>
                    <label for="public_dashboard_enabled" class="block text-xs font-semibold text-slateink mb-1">Mode Akses Dashboard &amp; Grafik</label>
                    <select id="public_dashboard_enabled" name="public_dashboard_enabled"
                            class="w-full min-h-[44px] sm:min-h-[40px] rounded-xl border border-slate-200 bg-canvas px-3.5 py-2 text-sm font-medium text-slate-900 focus:border-cobalt-800">
                        <option value="1" @selected(old('public_dashboard_enabled', $publicDashboardEnabled ? '1' : '0') === '1')>
                            Publik Read-Only (Pengunjung dapat memantau tanpa login; pengaturan khusus Admin)
                        </option>
                        <option value="0" @selected(old('public_dashboard_enabled', $publicDashboardEnabled ? '1' : '0') === '0')>
                            Privat (Seluruh halaman wajib Login Admin terlebih dahulu)
                        </option>
                    </select>
                </div>

                <div class="pt-2 flex justify-end">
                    <button type="submit"
                            class="min-h-[44px] px-5 py-2.5 rounded-xl hero-cobalt-card text-white text-sm font-semibold shadow-sm hover:opacity-95 transition">
                        Simpan Pengaturan Sistem
                    </button>
                </div>
            </form>
        </div>

        <!-- Admin Account + Reset Test Data -->
        <div class="lg:col-span-5 space-y-6">
            <div class="instrument-card rounded-3xl p-6">
                <h2 class="text-lg font-medium text-slate-900 mb-1">Akun Administrator</h2>
                <p class="text-xs text-slateink mb-4">Perbarui identitas login atau ganti password Admin.</p>

                <form method="POST" action="{{ route('settings.account') }}" class="space-y-3.5">
                    @csrf
                    @method('PUT')

                    <div>
                        <label for="admin-name" class="block text-xs font-semibold text-slateink mb-1">Nama</label>
                        <input id="admin-name" type="text" name="name" value="{{ old('name', $user->name) }}" required
                               class="w-full min-h-[44px] sm:min-h-[40px] rounded-xl border border-slate-200 bg-canvas px-3.5 py-2 text-sm text-slate-900 focus:border-cobalt-800">
                    </div>

                    <div class="space-y-3.5">
                        <div>
                            <label for="admin-username" class="block text-xs font-semibold text-slateink mb-1">Username</label>
                            <input id="admin-username" type="text" name="username" value="{{ old('username', $user->username) }}" required
                                   class="w-full min-h-[44px] sm:min-h-[40px] rounded-xl border border-slate-200 bg-canvas px-3.5 py-2 text-sm text-slate-900 focus:border-cobalt-800">
                        </div>
                        <div>
                            <label for="admin-email" class="block text-xs font-semibold text-slateink mb-1">Email</label>
                            <input id="admin-email" type="email" name="email" value="{{ old('email', $user->email) }}" required
                                   class="w-full min-h-[44px] sm:min-h-[40px] rounded-xl border border-slate-200 bg-canvas px-3.5 py-2 text-sm text-slate-900 focus:border-cobalt-800">
                        </div>
                    </div>

                    <div class="pt-2 border-t border-slate-100">
                        <div class="text-xs font-semibold text-slateink mb-2">Ganti Password (Opsional)</div>
                        <div class="space-y-2.5">
                            <input type="password" name="current_password" autocomplete="current-password" placeholder="Password saat ini" aria-label="Password saat ini"
                                   class="w-full min-h-[44px] sm:min-h-[40px] rounded-xl border border-slate-200 bg-canvas px-3.5 py-2 text-sm text-slate-900 focus:border-cobalt-800">
                            <input type="password" name="new_password" autocomplete="new-password" placeholder="Password baru (min. 8 karakter)" aria-label="Password baru"
                                   class="w-full min-h-[44px] sm:min-h-[40px] rounded-xl border border-slate-200 bg-canvas px-3.5 py-2 text-sm text-slate-900 focus:border-cobalt-800">
                            <input type="password" name="new_password_confirmation" autocomplete="new-password" placeholder="Konfirmasi password baru" aria-label="Konfirmasi password baru"
                                   class="w-full min-h-[44px] sm:min-h-[40px] rounded-xl border border-slate-200 bg-canvas px-3.5 py-2 text-sm text-slate-900 focus:border-cobalt-800">
                        </div>
                    </div>

                    <div class="pt-2 flex justify-end">
                        <button type="submit"
                                class="min-h-[44px] px-4 py-2 rounded-xl hero-cobalt-card text-white text-sm font-semibold shadow-sm hover:opacity-95 transition">
                            Perbarui Akun
                        </button>
                    </div>
                </form>
            </div>

            <!-- Reset Test Data Box -->
            <div class="instrument-card rounded-3xl border-red-200 p-6">
                <h2 class="text-lg font-medium text-[#BA1A1A] mb-1">Reset Data Pembacaan Sensor</h2>
                <p class="text-xs text-slateink leading-relaxed mb-4">
                    Saat ini tersimpan <strong class="text-slate-900">{{ number_format($totalReadingsCount, 0, ',', '.') }}</strong> baris data di tabel <code class="font-mono text-slate-900">sensor_readings</code>.
                    Gunakan tombol ini jika Anda ingin membersihkan data simulasi sebelum memulai pengambilan data aktual dari ESP32.
                </p>

                <form method="POST" action="{{ route('settings.clear-readings') }}"
                      onsubmit="return confirm('Yakin ingin menghapus seluruh riwayat data sensor? Tindakan ini tidak dapat dibatalkan.');">
                    @csrf
                    <button type="submit"
                            class="min-h-[44px] sm:min-h-[40px] px-4 py-2 rounded-xl bg-[#BA1A1A] hover:bg-red-800 text-white text-xs font-semibold transition">
                        Kosongkan Data Sensor
                    </button>
                </form>
            </div>
        </div>
    </div>
@endsection
