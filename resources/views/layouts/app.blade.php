<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <meta name="theme-color" content="#1E40AF">
    <meta name="mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-status-bar-style" content="default">
    <meta name="apple-mobile-web-app-title" content="SolarMonitor">
    <link rel="manifest" href="/manifest.webmanifest">
    <link rel="icon" type="image/svg+xml" href="/icons/icon-192.svg">
    <link rel="apple-touch-icon" href="/icons/icon-192.svg">
    <title>@yield('title', $siteName ?? 'SolarMonitor IoT')</title>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="bg-canvas text-slate-900 antialiased overflow-x-hidden font-sans">
<a href="#main-content"
   class="sr-only focus:not-sr-only focus:fixed focus:top-3 focus:left-3 focus:z-50 focus:px-4 focus:py-2.5 focus:rounded-xl focus:bg-cobalt-800 focus:text-white focus:text-sm focus:font-semibold">
    Loncat ke konten utama
</a>

@php
    $shellDeviceCode = $shellTelemetry['device_code'] ?? 'ESP32_SOLAR_01';
    $shellOnline = (bool) ($shellTelemetry['is_online'] ?? false);
    $shellOnlineCount = (int) ($shellTelemetry['online_count'] ?? 0);
    $shellTotalCount = (int) ($shellTelemetry['total_count'] ?? 0);
    $shellIntervalSec = (int) ($shellTelemetry['send_interval_sec'] ?? 5);
@endphp

<div class="min-h-screen flex">
    <!-- Mobile Drawer Backdrop -->
    <div id="sidebar-backdrop"
         class="fixed inset-0 z-30 bg-slate-900/50 hidden lg:hidden"
         aria-hidden="true"></div>

    <!-- Desktop Sidebar & Mobile Slide-Over Drawer (Matches FigCraft Desktop Reference) -->
    <aside id="app-sidebar"
           aria-label="Navigasi utama"
           class="fixed inset-y-0 left-0 z-40 w-72 lg:w-64 bg-white border-r border-slate-200/80 shadow-[0_1px_12px_rgba(15,23,42,0.04)] flex flex-col justify-between transform -translate-x-full transition-transform duration-200 ease-out lg:translate-x-0 lg:sticky lg:top-0 lg:h-screen shrink-0">
        <div class="flex flex-col flex-1 min-h-0 overflow-y-auto px-4 py-6 gap-6">
            <!-- Brand Header -->
            <div class="flex items-center justify-between gap-2 px-1">
                <a href="{{ route('dashboard') }}" class="flex items-center gap-3 min-h-[44px] min-w-0">
                    <div class="w-10 h-10 rounded-xl hero-cobalt-card text-white flex items-center justify-center shadow-sm shrink-0">
                        <svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M12 3v2.25m6.364.386-1.591 1.591M21 12h-2.25m-.386 6.364-1.591-1.591M12 18.75V21m-4.773-4.227-1.591 1.591M5.25 12H3m4.227-4.773L5.636 5.636M15.75 12a3.75 3.75 0 1 1-7.5 0 3.75 3.75 0 0 1 7.5 0Z"/>
                        </svg>
                    </div>
                    <div class="min-w-0">
                        <div class="text-base font-bold text-slate-900 tracking-tight leading-tight truncate">SolarMonitor</div>
                        <div class="text-[11px] font-medium text-slateink truncate">ESP32 Solar Telemetry</div>
                    </div>
                </a>
                <button type="button"
                        id="sidebar-close-btn"
                        class="lg:hidden inline-flex items-center justify-center min-w-[44px] min-h-[44px] rounded-xl text-slateink hover:bg-slate-100 active:bg-slate-200"
                        aria-label="Tutup menu navigasi">
                    <svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M6 18 18 6M6 6l12 12"/>
                    </svg>
                </button>
            </div>

            <!-- MENU UTAMA -->
            <nav aria-label="Menu aplikasi" class="space-y-1.5">
                <p class="px-3 mb-2 text-[11px] font-semibold uppercase tracking-wider text-slateink">Menu Utama</p>

                <a href="{{ route('dashboard') }}"
                   aria-current="{{ request()->routeIs('dashboard') ? 'page' : 'false' }}"
                   class="flex items-center gap-3 px-3.5 min-h-[44px] rounded-xl text-[13px] font-medium transition {{ request()->routeIs('dashboard') ? 'hero-cobalt-card text-white font-semibold shadow-[0_8px_20px_-4px_rgba(30,64,175,0.28)]' : 'text-slateink hover:bg-cobalt-50 hover:text-cobalt-800' }}">
                    <svg class="w-[18px] h-[18px] shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.9" aria-hidden="true">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M3.75 6A2.25 2.25 0 0 1 6 3.75h2.25A2.25 2.25 0 0 1 10.5 6v2.25a2.25 2.25 0 0 1-2.25 2.25H6a2.25 2.25 0 0 1-2.25-2.25V6Zm0 9.75A2.25 2.25 0 0 1 6 13.5h2.25a2.25 2.25 0 0 1 2.25 2.25V18a2.25 2.25 0 0 1-2.25 2.25H6A2.25 2.25 0 0 1 3.75 18v-2.25ZM13.5 6a2.25 2.25 0 0 1 2.25-2.25H18A2.25 2.25 0 0 1 20.25 6v2.25A2.25 2.25 0 0 1 18 10.5h-2.25a2.25 2.25 0 0 1-2.25-2.25V6Zm0 9.75a2.25 2.25 0 0 1 2.25-2.25H18a2.25 2.25 0 0 1 2.25 2.25V18A2.25 2.25 0 0 1 18 20.25h-2.25A2.25 2.25 0 0 1 13.5 18v-2.25Z"/>
                    </svg>
                    <span>Dashboard Monitoring</span>
                </a>

                <a href="{{ route('monitoring.realtime') }}"
                   aria-current="{{ request()->routeIs('monitoring.realtime') ? 'page' : 'false' }}"
                   class="flex items-center gap-3 px-3.5 min-h-[44px] rounded-xl text-[13px] font-medium transition {{ request()->routeIs('monitoring.realtime') ? 'hero-cobalt-card text-white font-semibold shadow-[0_8px_20px_-4px_rgba(30,64,175,0.28)]' : 'text-slateink hover:bg-cobalt-50 hover:text-cobalt-800' }}">
                    <svg class="w-[18px] h-[18px] shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.9" aria-hidden="true">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M3 13h3l2.5-6 4 10 2.5-6h6"/>
                    </svg>
                    <span>Monitoring Real-Time</span>
                </a>

                <a href="{{ route('monitoring.history') }}"
                   aria-current="{{ request()->routeIs('monitoring.history') ? 'page' : 'false' }}"
                   class="flex items-center gap-3 px-3.5 min-h-[44px] rounded-xl text-[13px] font-medium transition {{ request()->routeIs('monitoring.history') ? 'hero-cobalt-card text-white font-semibold shadow-[0_8px_20px_-4px_rgba(30,64,175,0.28)]' : 'text-slateink hover:bg-cobalt-50 hover:text-cobalt-800' }}">
                    <svg class="w-[18px] h-[18px] shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.9" aria-hidden="true">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M3 3v18h18M7 15l4-4 3 3 5-6"/>
                    </svg>
                    <span>Grafik Historis</span>
                </a>

                <a href="{{ route('logs.index') }}"
                   aria-current="{{ request()->routeIs('logs.*') ? 'page' : 'false' }}"
                   class="flex items-center gap-3 px-3.5 min-h-[44px] rounded-xl text-[13px] font-medium transition {{ request()->routeIs('logs.*') ? 'hero-cobalt-card text-white font-semibold shadow-[0_8px_20px_-4px_rgba(30,64,175,0.28)]' : 'text-slateink hover:bg-cobalt-50 hover:text-cobalt-800' }}">
                    <svg class="w-[18px] h-[18px] shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.9" aria-hidden="true">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M4 6h16M4 10h16M4 14h16M4 18h10"/>
                    </svg>
                    <span>Data Logging</span>
                </a>

                <a href="{{ route('analysis.index') }}"
                   aria-current="{{ request()->routeIs('analysis.*') ? 'page' : 'false' }}"
                   class="flex items-center gap-3 px-3.5 min-h-[44px] rounded-xl text-[13px] font-medium transition {{ request()->routeIs('analysis.*') ? 'hero-cobalt-card text-white font-semibold shadow-[0_8px_20px_-4px_rgba(30,64,175,0.28)]' : 'text-slateink hover:bg-cobalt-50 hover:text-cobalt-800' }}">
                    <svg class="w-[18px] h-[18px] shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.9" aria-hidden="true">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M9 19v-6a2 2 0 0 0-2-2H5a2 2 0 0 0-2 2v6a2 2 0 0 0 2 2h2a2 2 0 0 0 2-2Zm0 0V9a2 2 0 0 1 2-2h2a2 2 0 0 1 2 2v10m-6 0a2 2 0 0 0 2 2h2a2 2 0 0 0 2-2m0 0V5a2 2 0 0 1 2-2h2a2 2 0 0 1 2 2v14a2 2 0 0 1-2 2h-2a2 2 0 0 1-2-2Z"/>
                    </svg>
                    <span>Analisis Data</span>
                </a>
            </nav>
        </div>

        <!-- Bottom Sidebar Widget & System Navigation (Matches FigCraft Desktop Reference) -->
        <div class="px-4 pb-5 pt-2 space-y-4">
            <!-- Real Telemetry Cycle Widget -->
            <div class="rounded-2xl bg-cobalt-50 border border-cobalt-100 p-3.5 space-y-2">
                <div class="flex items-center justify-between text-xs">
                    <span class="font-medium text-slateink">Siklus Pengiriman</span>
                    <span class="font-bold text-cobalt-800 tabular-nums">{{ $shellIntervalSec }} dtk</span>
                </div>
                <div class="w-full h-1.5 rounded-full bg-cobalt-100 overflow-hidden">
                    <div class="h-full rounded-full bg-cobalt-800 {{ $shellOnline ? 'w-4/5' : 'w-1/4' }}"></div>
                </div>
                <div class="flex items-center justify-between text-[11px] font-medium text-slateink">
                    <span>HTTP REST API</span>
                    <span class="font-semibold {{ $shellOnline ? 'text-emerald-700' : 'text-red-700' }}">
                        {{ $shellOnlineCount }}/{{ $shellTotalCount }} Node Aktif
                    </span>
                </div>
            </div>

            <div class="pt-3 border-t border-slate-200/80 space-y-1">
                <p class="px-3 mb-1.5 text-[11px] font-semibold uppercase tracking-wider text-slateink">Sistem &amp; Perangkat</p>

                <a href="{{ route('devices.index') }}"
                   aria-current="{{ request()->routeIs('devices.*') ? 'page' : 'false' }}"
                   class="flex items-center justify-between gap-2 px-3.5 min-h-[44px] rounded-xl text-[13px] font-medium transition {{ request()->routeIs('devices.*') ? 'hero-cobalt-card text-white font-semibold shadow-[0_8px_20px_-4px_rgba(30,64,175,0.28)]' : 'text-slateink hover:bg-cobalt-50 hover:text-cobalt-800' }}">
                    <span class="flex items-center gap-3 min-w-0">
                        <svg class="w-[18px] h-[18px] shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.9" aria-hidden="true">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M9 3v2m6-2v2M9 19v2m6-2v2M5 9H3m2 6H3m18-6h-2m2 6h-2M7 19h10a2 2 0 0 0 2-2V7a2 2 0 0 0-2-2H7a2 2 0 0 0-2 2v10a2 2 0 0 0 2 2ZM9 9h6v6H9V9Z"/>
                        </svg>
                        <span class="truncate">Manajemen Perangkat</span>
                    </span>
                    @guest
                        <span class="text-[11px] font-semibold px-1.5 py-0.5 rounded bg-slate-100 text-slateink">Admin</span>
                    @endguest
                </a>

                <a href="{{ route('settings.index') }}"
                   aria-current="{{ request()->routeIs('settings.*') ? 'page' : 'false' }}"
                   class="flex items-center justify-between gap-2 px-3.5 min-h-[44px] rounded-xl text-[13px] font-medium transition {{ request()->routeIs('settings.*') ? 'hero-cobalt-card text-white font-semibold shadow-[0_8px_20px_-4px_rgba(30,64,175,0.28)]' : 'text-slateink hover:bg-cobalt-50 hover:text-cobalt-800' }}">
                    <span class="flex items-center gap-3 min-w-0">
                        <svg class="w-[18px] h-[18px] shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.9" aria-hidden="true">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M10.325 4.317c.426-1.756 2.924-1.756 3.35 0a1.724 1.724 0 0 0 2.573 1.066c1.543-.94 3.31.826 2.37 2.37a1.724 1.724 0 0 0 1.065 2.572c1.756.426 1.756 2.924 0 3.35a1.724 1.724 0 0 0-1.066 2.573c.94 1.543-.826 3.31-2.37 2.37a1.724 1.724 0 0 0-2.572 1.065c-.426 1.756-2.924 1.756-3.35 0a1.724 1.724 0 0 0-2.573-1.066c-1.543.94-3.31-.826-2.37-2.37a1.724 1.724 0 0 0-1.065-2.572c-1.756-.426-1.756-2.924 0-3.35a1.724 1.724 0 0 0 1.066-2.573c-.94-1.543.826-3.31 2.37-2.37.996.608 2.296.07 2.572-1.065Z"/>
                            <path stroke-linecap="round" stroke-linejoin="round" d="M15 12a3 3 0 1 1-6 0 3 3 0 0 1 6 0Z"/>
                        </svg>
                        <span class="truncate">Pengaturan Sistem</span>
                    </span>
                    @guest
                        <span class="text-[11px] font-semibold px-1.5 py-0.5 rounded bg-slate-100 text-slateink">Admin</span>
                    @endguest
                </a>

                <!-- PWA Install CTA inside Mobile Drawer (Shown when browser fires beforeinstallprompt) -->
                <button type="button"
                        id="pwa-install-drawer-btn"
                        class="hidden w-full items-center gap-3 px-3.5 min-h-[44px] rounded-xl text-[13px] font-semibold bg-cobalt-50 text-cobalt-800 border border-cobalt-100 hover:bg-cobalt-100 transition">
                    <svg class="w-[18px] h-[18px] shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.9" aria-hidden="true">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M3 16.5v2.25A2.25 2.25 0 0 0 5.25 21h13.5A2.25 2.25 0 0 0 21 18.75V16.5M16.5 12 12 16.5m0 0L7.5 12m4.5 4.5V3"/>
                    </svg>
                    <span>Pasang Aplikasi (PWA)</span>
                </button>

                @auth
                    <form method="POST" action="{{ route('logout') }}">
                        @csrf
                        <button type="submit"
                                class="w-full flex items-center gap-3 px-3.5 min-h-[44px] rounded-xl text-[13px] font-semibold text-[#BA1A1A] hover:bg-red-50 active:bg-red-100 transition">
                            <svg class="w-[18px] h-[18px] shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.9" aria-hidden="true">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M17 16l4-4m0 0-4-4m4 4H7m6 4v1a3 3 0 0 1-3 3H6a3 3 0 0 1-3-3V7a3 3 0 0 1 3-3h4a3 3 0 0 1 3 3v1"/>
                            </svg>
                            <span>Logout</span>
                        </button>
                    </form>
                @else
                    <a href="{{ route('login') }}"
                       class="flex items-center gap-3 px-3.5 min-h-[44px] rounded-xl text-[13px] font-semibold text-cobalt-800 hover:bg-cobalt-50 active:bg-cobalt-100 transition">
                        <svg class="w-[18px] h-[18px] shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.9" aria-hidden="true">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M11 16l-4-4m0 0 4-4m-4 4h14m-5 4v1a3 3 0 0 1-3 3H6a3 3 0 0 1-3-3V7a3 3 0 0 1 3-3h7a3 3 0 0 1 3 3v1"/>
                        </svg>
                        <span>Login Admin</span>
                    </a>
                @endauth
            </div>
        </div>
    </aside>

    <!-- Main Workspace Shell -->
    <div class="flex-1 flex flex-col min-w-0">
        <!-- Sticky Translucent Top Header Bar (Desktop & Mobile Aligned to FigCraft Reference) -->
        <header class="sticky top-0 z-20 bg-canvas/85 backdrop-blur-xl border-b border-slate-200/70 px-4 sm:px-6 lg:px-8 h-16 flex items-center justify-between gap-3">
            <!-- Left: Mobile Menu Button + Brand (Mobile) OR Global Search Bar (Desktop) -->
            <div class="flex items-center gap-2.5 flex-1 min-w-0">
                <button type="button"
                        id="sidebar-open-btn"
                        aria-expanded="false"
                        aria-controls="app-sidebar"
                        class="lg:hidden inline-flex items-center gap-1.5 px-3 min-h-[44px] rounded-xl border border-slate-200 bg-white text-slate-900 hover:bg-slate-50 text-xs font-semibold shrink-0 shadow-sm">
                    <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M4 6h16M4 12h16M4 18h16"/>
                    </svg>
                    <span>Menu</span>
                </button>

                <!-- Mobile Header Identity -->
                <div class="lg:hidden min-w-0 flex-1">
                    <div class="text-sm font-bold text-slate-900 leading-tight truncate">SolarMonitor</div>
                    <div class="flex items-center gap-1.5 text-[11px] font-medium {{ $shellOnline ? 'text-emerald-700' : 'text-[#BA1A1A]' }}">
                        <span class="w-1.5 h-1.5 rounded-full shrink-0 {{ $shellOnline ? 'bg-emerald-700' : 'bg-[#BA1A1A]' }}" aria-hidden="true"></span>
                        <span class="truncate">{{ $shellOnline ? 'Online' : 'Offline' }} &bull; {{ $shellDeviceCode }}</span>
                    </div>
                </div>

                <!-- Desktop Search Input (Navigates cleanly to /logs?search=...) -->
                <form method="GET" action="{{ route('logs.index') }}" class="hidden lg:flex items-center w-full max-w-sm">
                    <label for="topbar-search" class="sr-only">Cari data parameter, log, atau perangkat</label>
                    <div class="relative w-full">
                        <svg class="w-4 h-4 text-slateink absolute left-3.5 top-1/2 -translate-y-1/2 pointer-events-none" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true">
                            <path stroke-linecap="round" stroke-linejoin="round" d="m21 21-4.35-4.35M17 11a6 6 0 1 1-12 0 6 6 0 0 1 12 0Z"/>
                        </svg>
                        <input id="topbar-search"
                               type="search"
                               name="search"
                               value="{{ request()->routeIs('logs.*') ? request()->query('search', '') : '' }}"
                               placeholder="Cari data parameter, log, atau sensor..."
                               class="w-full h-10 pl-10 pr-4 rounded-xl bg-white border border-slate-200/80 text-xs font-medium text-slate-900 placeholder:text-slateink shadow-sm focus:border-cobalt-800">
                    </div>
                </form>
            </div>

            <!-- Right: Device Status Pill + Refresh Button + PWA Install + Profile -->
            <div class="flex items-center gap-2 sm:gap-3 shrink-0">
                <!-- Desktop Live Node Pill -->
                <a href="{{ auth()->check() ? route('devices.index') : route('dashboard') }}"
                   id="shell-status-pill"
                   class="hidden md:inline-flex items-center gap-2 px-3.5 h-9 rounded-full bg-white border border-slate-200/80 shadow-sm text-xs font-semibold {{ $shellOnline ? 'text-emerald-700' : 'text-red-700' }} hover:bg-slate-50 transition">
                    <span class="w-2 h-2 rounded-full {{ $shellOnline ? 'bg-emerald-700' : 'bg-red-700' }}" aria-hidden="true"></span>
                    <span>{{ $shellOnline ? 'ESP32 Online' : 'ESP32 Offline' }} &bull; {{ $shellDeviceCode }}</span>
                </a>

                <!-- PWA Install CTA (Desktop inline when installable; Mobile installs via Menu drawer) -->
                <button type="button"
                        id="pwa-install-btn"
                        class="hidden items-center gap-1.5 px-3 min-h-[36px] rounded-xl bg-cobalt-50 text-cobalt-800 border border-cobalt-100 hover:bg-cobalt-100 text-xs font-semibold transition">
                    <span>Pasang App</span>
                </button>

                <!-- Refresh View Circle Button -->
                <button type="button"
                        onclick="window.location.reload()"
                        title="Muat ulang halaman"
                        aria-label="Muat ulang halaman"
                        class="hidden sm:inline-flex items-center justify-center w-10 h-10 rounded-full bg-white border border-slate-200/80 text-slateink hover:bg-slate-50 hover:text-cobalt-800 shadow-sm transition">
                    <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M4 4v5h.582m15.356 2A8.001 8.001 0 0 0 4.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 0 1-15.357-2m15.357 2H15"/>
                    </svg>
                </button>

                <!-- User / Admin Identity -->
                <!-- PWA Install CTA inside Mobile Drawer (Shown when browser fires beforeinstallprompt) -->
                <button type="button"
                        id="pwa-install-drawer-btn"
                        class="hidden w-full items-center gap-3 px-3.5 min-h-[44px] rounded-xl text-[13px] font-semibold bg-cobalt-50 text-cobalt-800 border border-cobalt-100 hover:bg-cobalt-100 transition">
                    <svg class="w-[18px] h-[18px] shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.9" aria-hidden="true">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M3 16.5v2.25A2.25 2.25 0 0 0 5.25 21h13.5A2.25 2.25 0 0 0 21 18.75V16.5M16.5 12 12 16.5m0 0L7.5 12m4.5 4.5V3"/>
                    </svg>
                    <span>Pasang Aplikasi (PWA)</span>
                </button>

                @auth
                    <a href="{{ route('settings.index') }}"
                       class="inline-flex items-center gap-2.5 pl-1.5 pr-1.5 sm:pr-3 py-1 rounded-full bg-white border border-slate-200/80 shadow-sm hover:bg-slate-50 transition min-h-[40px]">
                        <div class="w-8 h-8 rounded-full hero-cobalt-card text-white flex items-center justify-center text-xs font-bold uppercase shrink-0" aria-hidden="true">
                            {{ substr(auth()->user()->name, 0, 1) }}
                        </div>
                        <div class="hidden sm:block text-left leading-tight">
                            <div class="text-xs font-semibold text-slate-900">{{ auth()->user()->name }}</div>
                            <div class="text-[11px] text-slateink capitalize">{{ auth()->user()->role }} Sistem</div>
                        </div>
                    </a>
                @else
                    <a href="{{ route('login') }}"
                       class="inline-flex items-center gap-1.5 px-3 sm:px-3.5 min-h-[44px] sm:min-h-[38px] rounded-xl text-xs font-semibold hero-cobalt-card text-white shadow-sm hover:opacity-95 transition whitespace-nowrap">
                        <span>Login</span>
                        <span class="hidden sm:inline">Admin</span>
                    </a>
                @endauth
            </div>
        </header>

        <!-- Page Body (reserves bottom space on mobile so bottom nav never overlaps content) -->
        <main id="main-content" class="flex-1 p-4 sm:p-6 lg:p-8 pb-24 lg:pb-10 max-w-7xl w-full mx-auto min-w-0">
            @hasSection('page_title')
                @if (! View::hasSection('hide_default_page_header'))
                    <div class="mb-6 flex flex-col md:flex-row md:items-end md:justify-between gap-4">
                        <div class="min-w-0">
                            <h1 class="text-2xl sm:text-3xl font-light text-slate-900 tracking-tight leading-tight">
                                @yield('page_title', 'Dashboard Monitoring')
                            </h1>
                            <p class="mt-1.5 text-sm text-slateink max-w-2xl">
                                @yield('page_subtitle', 'Pemantauan parameter kelistrikan, intensitas cahaya, dan temperatur panel surya berbasis ESP32')
                            </p>
                        </div>
                        @yield('page_actions')
                    </div>
                @endif
            @endif

            @yield('content')
        </main>
    </div>
</div>

<!-- Fixed Mobile Bottom Navigation Bar (App-Like PWA Navigation, >= 48px touch targets) -->
<nav aria-label="Navigasi cepat mobile"
     class="lg:hidden fixed bottom-0 inset-x-0 z-20 bg-white border-t border-slate-200 shadow-[0_-4px_16px_rgba(15,23,42,0.06)] safe-bottom">
    <div class="grid grid-cols-5 max-w-lg mx-auto px-1 py-1">
        <a href="{{ route('dashboard') }}"
           aria-current="{{ request()->routeIs('dashboard') ? 'page' : 'false' }}"
           class="flex flex-col items-center justify-center min-h-[48px] rounded-xl text-[11px] font-semibold transition {{ request()->routeIs('dashboard') ? 'text-cobalt-800 bg-cobalt-50' : 'text-slateink hover:bg-slate-50 active:bg-slate-100' }}">
            <svg class="w-5 h-5 mb-0.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true">
                <path stroke-linecap="round" stroke-linejoin="round" d="M3.75 6A2.25 2.25 0 0 1 6 3.75h2.25A2.25 2.25 0 0 1 10.5 6v2.25a2.25 2.25 0 0 1-2.25 2.25H6a2.25 2.25 0 0 1-2.25-2.25V6Zm0 9.75A2.25 2.25 0 0 1 6 13.5h2.25a2.25 2.25 0 0 1 2.25 2.25V18a2.25 2.25 0 0 1-2.25 2.25H6A2.25 2.25 0 0 1 3.75 18v-2.25ZM13.5 6a2.25 2.25 0 0 1 2.25-2.25H18A2.25 2.25 0 0 1 20.25 6v2.25A2.25 2.25 0 0 1 18 10.5h-2.25a2.25 2.25 0 0 1-2.25-2.25V6Z"/>
            </svg>
            <span>Beranda</span>
        </a>

        <a href="{{ route('monitoring.realtime') }}"
           aria-current="{{ request()->routeIs('monitoring.*') ? 'page' : 'false' }}"
           class="flex flex-col items-center justify-center min-h-[48px] rounded-xl text-[11px] font-semibold transition {{ request()->routeIs('monitoring.*') ? 'text-cobalt-800 bg-cobalt-50' : 'text-slateink hover:bg-slate-50 active:bg-slate-100' }}">
            <svg class="w-5 h-5 mb-0.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true">
                <path stroke-linecap="round" stroke-linejoin="round" d="M3 13h3l2.5-6 4 10 2.5-6h6"/>
            </svg>
            <span>Grafik</span>
        </a>

        <a href="{{ route('logs.index') }}"
           aria-current="{{ request()->routeIs('logs.*') ? 'page' : 'false' }}"
           class="flex flex-col items-center justify-center min-h-[48px] rounded-xl text-[11px] font-semibold transition {{ request()->routeIs('logs.*') ? 'text-cobalt-800 bg-cobalt-50' : 'text-slateink hover:bg-slate-50 active:bg-slate-100' }}">
            <svg class="w-5 h-5 mb-0.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true">
                <path stroke-linecap="round" stroke-linejoin="round" d="M4 6h16M4 10h16M4 14h16M4 18h10"/>
            </svg>
            <span>Log</span>
        </a>

        <a href="{{ route('analysis.index') }}"
           aria-current="{{ request()->routeIs('analysis.*') ? 'page' : 'false' }}"
           class="flex flex-col items-center justify-center min-h-[48px] rounded-xl text-[11px] font-semibold transition {{ request()->routeIs('analysis.*') ? 'text-cobalt-800 bg-cobalt-50' : 'text-slateink hover:bg-slate-50 active:bg-slate-100' }}">
            <svg class="w-5 h-5 mb-0.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true">
                <path stroke-linecap="round" stroke-linejoin="round" d="M9 19v-6a2 2 0 0 0-2-2H5a2 2 0 0 0-2 2v6a2 2 0 0 0 2 2h2a2 2 0 0 0 2-2Zm0 0V9a2 2 0 0 1 2-2h2a2 2 0 0 1 2 2v10"/>
            </svg>
            <span>Analisis</span>
        </a>

        <a href="{{ route('devices.index') }}"
           aria-current="{{ request()->routeIs('devices.*') ? 'page' : 'false' }}"
           class="flex flex-col items-center justify-center min-h-[48px] rounded-xl text-[11px] font-semibold transition {{ request()->routeIs('devices.*') ? 'text-cobalt-800 bg-cobalt-50' : 'text-slateink hover:bg-slate-50 active:bg-slate-100' }}">
            <svg class="w-5 h-5 mb-0.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true">
                <path stroke-linecap="round" stroke-linejoin="round" d="M9 3v2m6-2v2M9 19v2m6-2v2M7 19h10a2 2 0 0 0 2-2V7a2 2 0 0 0-2-2H7a2 2 0 0 0-2 2v10a2 2 0 0 0 2 2Z"/>
            </svg>
            <span>Perangkat</span>
        </a>
    </div>
</nav>

<script>
    (function () {
        const sidebar = document.getElementById('app-sidebar');
        const backdrop = document.getElementById('sidebar-backdrop');
        const openBtn = document.getElementById('sidebar-open-btn');
        const closeBtn = document.getElementById('sidebar-close-btn');

        function setSidebar(open) {
            if (!sidebar || !backdrop || !openBtn) return;
            openBtn.setAttribute('aria-expanded', open ? 'true' : 'false');
            if (open) {
                sidebar.classList.remove('-translate-x-full');
                backdrop.classList.remove('hidden');
                if (closeBtn) closeBtn.focus();
            } else {
                sidebar.classList.add('-translate-x-full');
                backdrop.classList.add('hidden');
                openBtn.focus();
            }
        }

        if (openBtn) openBtn.addEventListener('click', () => setSidebar(true));
        if (closeBtn) closeBtn.addEventListener('click', () => setSidebar(false));
        if (backdrop) backdrop.addEventListener('click', () => setSidebar(false));

        document.addEventListener('keydown', (e) => {
            if (e.key === 'Escape' && sidebar && !sidebar.classList.contains('-translate-x-full') && window.innerWidth < 1024) {
                setSidebar(false);
            }
        });

        // PWA Service Worker & Install Prompt
        if ('serviceWorker' in navigator) {
            navigator.serviceWorker.register('/sw.js').catch(() => {});
        }

        let deferredPrompt = null;
        const installBtn = document.getElementById('pwa-install-btn');
        const installDrawerBtn = document.getElementById('pwa-install-drawer-btn');
        window.addEventListener('beforeinstallprompt', (e) => {
            e.preventDefault();
            deferredPrompt = e;
            if (installBtn) {
                installBtn.classList.remove('hidden');
                installBtn.classList.add('sm:inline-flex');
            }
            if (installDrawerBtn) {
                installDrawerBtn.classList.remove('hidden');
                installDrawerBtn.classList.add('flex');
            }
        });

        async function triggerPwaInstall() {
            if (!deferredPrompt) return;
            deferredPrompt.prompt();
            await deferredPrompt.userChoice;
            deferredPrompt = null;
            if (installBtn) {
                installBtn.classList.add('hidden');
                installBtn.classList.remove('sm:inline-flex');
            }
            if (installDrawerBtn) {
                installDrawerBtn.classList.add('hidden');
                installDrawerBtn.classList.remove('flex');
            }
        }

        if (installBtn) installBtn.addEventListener('click', triggerPwaInstall);
        if (installDrawerBtn) installDrawerBtn.addEventListener('click', triggerPwaInstall);
    })();
</script>
@stack('scripts')
</body>
</html>
