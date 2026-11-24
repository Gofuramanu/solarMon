<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <meta name="theme-color" content="#1D4ED8">
    <link rel="manifest" href="/manifest.webmanifest">
    <link rel="icon" type="image/svg+xml" href="/icons/icon-192.svg">
    <title>Login Admin | {{ $siteName }}</title>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="min-h-screen bg-canvas text-slate-900 flex items-center justify-center p-4">
<main class="w-full max-w-4xl bg-white rounded-3xl shadow-[0_12px_36px_-8px_rgba(30,64,175,0.12)] border border-slate-200/80 overflow-hidden grid grid-cols-1 md:grid-cols-12">
    <!-- Left Identity Panel (Deep Cobalt Instrumentation) -->
    <div class="md:col-span-5 hero-cobalt-card text-white p-7 sm:p-8 flex flex-col justify-between">
        <div>
            <div class="flex items-center gap-3">
                <img src="/icons/icon-192.svg" alt="Ikon SolarMonitor" width="40" height="40" class="w-10 h-10 rounded-xl shrink-0">
                <div>
                    <div class="font-bold text-base tracking-tight">SolarMonitor</div>
                    <div class="text-xs text-cobalt-100">ESP32 Solar Telemetry</div>
                </div>
            </div>

            <h2 class="mt-7 text-xl sm:text-2xl font-bold leading-snug">
                Monitoring Panel Surya dan Data Logging MySQL
            </h2>
            <p class="mt-3 text-sm text-cobalt-100 leading-relaxed">
                Pantau tegangan, arus, daya listrik, suhu permukaan panel, dan intensitas cahaya yang dikirim langsung oleh ESP32 melalui HTTP REST API.
            </p>
        </div>

        <div class="mt-7 pt-5 border-t border-cobalt-600 space-y-1.5 text-xs text-cobalt-100">
            <div>Sensor aktif: INA219, DS18B20, BH1750, LCD 16x2</div>
            <div>Penyimpanan: MySQL dengan integrasi energi Wh dan kWh</div>
        </div>
    </div>

    <!-- Right Login Form -->
    <div class="md:col-span-7 p-7 sm:p-10 flex flex-col justify-center">
        <div class="mb-6">
            <h1 class="text-2xl font-bold text-slate-900">Masuk sebagai Admin</h1>
            <p class="mt-1 text-sm text-slate-600">Gunakan email atau username Admin yang terdaftar untuk mengelola perangkat dan pengaturan sistem.</p>
        </div>

        @if (session('status'))
            <div role="status" class="mb-5 rounded-xl bg-emerald-50 border border-emerald-200 px-4 py-3 text-sm text-emerald-700">
                {{ session('status') }}
            </div>
        @endif

        @if ($errors->any())
            <div role="alert" class="mb-5 rounded-xl bg-red-50 border border-red-200 px-4 py-3 text-sm text-red-700">
                <ul class="list-disc list-inside space-y-1">
                    @foreach ($errors->all() as $error)
                        <li>{{ $error }}</li>
                    @endforeach
                </ul>
            </div>
        @endif

        <form method="POST" action="{{ route('login.attempt') }}" class="space-y-4">
            @csrf

            <div>
                <label for="login" class="block text-sm font-semibold text-slate-800 mb-1.5">Email atau Username</label>
                <input id="login"
                       name="login"
                       type="text"
                       value="{{ old('login') }}"
                       required
                       autofocus
                       autocomplete="username"
                       placeholder="Masukkan email atau username"
                       class="w-full min-h-[44px] rounded-xl border border-slate-300 px-4 py-2.5 text-sm text-slate-900 placeholder-slate-500 focus:border-cobalt-700 focus:ring-2 focus:ring-cobalt-700/20">
            </div>

            <div>
                <label for="password" class="block text-sm font-semibold text-slate-800 mb-1.5">Password</label>
                <div class="relative">
                    <input id="password"
                           name="password"
                           type="password"
                           required
                           autocomplete="current-password"
                           placeholder="Masukkan password"
                           class="w-full min-h-[44px] rounded-xl border border-slate-300 px-4 py-2.5 pr-20 text-sm text-slate-900 placeholder-slate-500 focus:border-cobalt-700 focus:ring-2 focus:ring-cobalt-700/20">
                    <button type="button"
                            id="toggle-password-btn"
                            aria-label="Tampilkan atau sembunyikan password"
                            class="absolute inset-y-0 right-1 my-1 px-3 rounded-lg text-xs font-semibold text-slate-700 hover:bg-slate-100">
                        Lihat
                    </button>
                </div>
            </div>

            <div class="flex items-center">
                <label for="remember" class="inline-flex items-center gap-2.5 min-h-[44px] text-sm text-slate-700">
                    <input id="remember" type="checkbox" name="remember" value="1" {{ old('remember') ? 'checked' : '' }}
                           class="w-4 h-4 rounded border-slate-400 text-cobalt-700 focus:ring-cobalt-700">
                    <span>Ingat sesi login saya</span>
                </label>
            </div>

            <button type="submit"
                    class="w-full min-h-[48px] px-4 rounded-xl hero-cobalt-card hover:opacity-95 text-white font-semibold text-sm shadow-sm transition">
                Masuk ke Dashboard
            </button>
        </form>

        @if ($publicDashboardEnabled)
            <div class="mt-6 pt-5 border-t border-slate-200 text-center">
                <a href="{{ route('dashboard') }}" class="inline-flex items-center justify-center min-h-[44px] px-3 text-sm font-semibold text-cobalt-700 hover:underline">
                    Kembali ke Dashboard Monitoring Publik
                </a>
            </div>
        @endif
    </div>
</main>

<script>
    (function () {
        const input = document.getElementById('password');
        const btn = document.getElementById('toggle-password-btn');
        if (!input || !btn) return;
        btn.addEventListener('click', () => {
            const isHidden = input.type === 'password';
            input.type = isHidden ? 'text' : 'password';
            btn.textContent = isHidden ? 'Sembunyi' : 'Lihat';
        });
    })();
</script>
</body>
</html>
