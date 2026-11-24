<script>
    (function () {
        const chartConfigs = [
            { key: 'voltage',     canvasId: 'chart-voltage',     label: 'Tegangan (V)',           color: '#1E40AF', bg: 'rgba(30, 64, 175, 0.14)',  unit: 'V',   decimals: 2 },
            { key: 'current',     canvasId: 'chart-current',     label: 'Arus Listrik (mA)',      color: '#0369A1', bg: 'rgba(3, 105, 161, 0.12)',  unit: 'mA',  decimals: 2 },
            { key: 'power',       canvasId: 'chart-power',       label: 'Daya Listrik (W)',       color: '#00288E', bg: 'rgba(0, 40, 142, 0.16)',   unit: 'W',   decimals: 3 },
            { key: 'temperature', canvasId: 'chart-temperature', label: 'Temperatur (°C)',        color: '#047857', bg: 'rgba(4, 120, 87, 0.12)',   unit: '°C',  decimals: 1 },
            { key: 'lux',         canvasId: 'chart-lux',         label: 'Intensitas Cahaya (Lux)',color: '#92400E', bg: 'rgba(217, 119, 6, 0.14)',  unit: 'Lux', decimals: 0 },
        ];

        const initialData = @json($initialChartData);
        const endpoint = @json(route('monitoring.chart-data'));
        const mode = @json($mode);
        const refreshMs = {{ $refreshIntervalSec * 1000 }};

        let state = {
            device: initialData.device ? initialData.device.device_code : '',
            period: initialData.period || '1h',
            date: initialData.date || '',
            liveEnabled: mode === 'realtime',
            timestamps: initialData.timestamps || [],
        };

        const instances = {};

        function bootCharts() {
            if (typeof window.Chart === 'undefined') {
                setTimeout(bootCharts, 50);
                return;
            }

            chartConfigs.forEach((cfg) => {
                const ctx = document.getElementById(cfg.canvasId);
                if (!ctx) return;

                instances[cfg.key] = new window.Chart(ctx, {
                    type: 'line',
                    data: {
                        labels: initialData.labels || [],
                        datasets: [{
                            label: cfg.label,
                            data: (initialData.series && initialData.series[cfg.key]) ? initialData.series[cfg.key] : [],
                            borderColor: cfg.color,
                            backgroundColor: cfg.bg,
                            borderWidth: 2.2,
                            pointRadius: (initialData.labels && initialData.labels.length > 60) ? 0 : 2.5,
                            pointHoverRadius: 5,
                            tension: 0.3,
                            fill: true,
                        }],
                    },
                    options: {
                        responsive: true,
                        maintainAspectRatio: false,
                        animation: false,
                        interaction: { mode: 'index', intersect: false },
                        plugins: {
                            legend: { display: false },
                            tooltip: {
                                callbacks: {
                                    title: (items) => {
                                        const idx = items[0]?.dataIndex;
                                        return (idx !== undefined && state.timestamps[idx]) ? state.timestamps[idx] : (items[0]?.label || '');
                                    },
                                    label: (ctxItem) => `${cfg.label}: ${Number(ctxItem.parsed.y).toFixed(cfg.decimals)} ${cfg.unit}`,
                                },
                            },
                        },
                        scales: {
                            x: {
                                grid: { display: false },
                                ticks: { maxTicksLimit: 8, color: '#475569', font: { size: 11 } },
                            },
                            y: {
                                beginAtZero: cfg.key !== 'temperature',
                                grid: { color: '#F1F5F9' },
                                ticks: { color: '#475569', font: { size: 11 } },
                            },
                        },
                    },
                });
            });
        }

        function syncFilterButtonsUI() {
            document.querySelectorAll('.period-btn').forEach((btn) => {
                const isActive = btn.dataset.period === state.period;
                btn.className = 'period-btn whitespace-nowrap min-h-[44px] sm:min-h-[38px] px-3 py-1.5 rounded-xl text-xs font-semibold border transition ' +
                    (isActive
                        ? 'hero-cobalt-card text-white border-cobalt-800 shadow-sm'
                        : 'bg-white text-slateink border-slate-200 hover:bg-cobalt-50 hover:text-cobalt-800');
            });

            const dateBtn = document.getElementById('apply-date-btn');
            if (dateBtn) {
                dateBtn.className = 'min-h-[44px] sm:min-h-[38px] px-3.5 py-1.5 rounded-xl text-xs font-semibold border transition ' +
                    (state.period === 'date'
                        ? 'hero-cobalt-card text-white border-cobalt-800'
                        : 'bg-cobalt-50 text-cobalt-800 border-cobalt-100 hover:bg-cobalt-100');
            }
        }

        function applyPayloadToUI(payload) {
            state.timestamps = payload.timestamps || [];

            const pointsEl = document.getElementById('chart-points-count');
            if (pointsEl) pointsEl.textContent = payload.total_points ?? 0;

            const emptyBanner = document.getElementById('charts-empty-banner');
            const emptyLabel = document.getElementById('empty-period-label');
            if (emptyBanner) {
                if ((payload.total_points ?? 0) > 0) emptyBanner.classList.add('hidden');
                else emptyBanner.classList.remove('hidden');
            }
            if (emptyLabel && payload.period_label) emptyLabel.textContent = payload.period_label;

            if (payload.device) {
                const badge = document.getElementById('chart-status-badge');
                const dot = document.getElementById('chart-status-dot');
                const text = document.getElementById('chart-status-text');
                if (badge && dot && text) {
                    const online = Boolean(payload.device.is_online);
                    badge.className = 'inline-flex items-center gap-2 px-3 py-1.5 rounded-full text-xs font-semibold border ' +
                        (online ? 'bg-emerald-50 text-emerald-800 border-emerald-200' : 'bg-red-50 text-red-700 border-red-200');
                    dot.className = 'w-2 h-2 rounded-full ' + (online ? 'bg-emerald-700' : 'bg-red-700');
                    text.textContent = online ? 'ESP32 Online' : 'ESP32 Offline';
                }
            }

            chartConfigs.forEach((cfg) => {
                const chart = instances[cfg.key];
                const values = (payload.series && payload.series[cfg.key]) ? payload.series[cfg.key] : [];
                if (chart) {
                    chart.data.labels = payload.labels || [];
                    chart.data.datasets[0].data = values;
                    chart.data.datasets[0].pointRadius = (payload.labels && payload.labels.length > 60) ? 0 : 2.5;
                    chart.update('none');
                }

                const badgeEl = document.getElementById('badge-' + cfg.key);
                if (badgeEl) {
                    if (payload.latest && payload.latest[cfg.key] !== undefined) {
                        const val = Number(payload.latest[cfg.key]);
                        badgeEl.textContent = (cfg.key === 'lux'
                            ? Math.round(val).toLocaleString('id-ID')
                            : val.toFixed(cfg.decimals)) + ' ' + cfg.unit;
                    } else {
                        badgeEl.textContent = '- ' + cfg.unit;
                    }
                }
            });
        }

        async function fetchAndUpdate() {
            if (!state.device) return;
            const params = new URLSearchParams({
                device: state.device,
                period: state.period,
            });
            if (state.period === 'date' && state.date) {
                params.set('date', state.date);
            }

            try {
                const res = await fetch(endpoint + '?' + params.toString(), {
                    headers: { 'Accept': 'application/json' },
                });
                if (!res.ok) return;
                const payload = await res.json();
                applyPayloadToUI(payload);
            } catch (e) {
                // Keep existing charts intact on transient network failure
            }
        }

        function updateBrowserQuery() {
            const url = new URL(window.location.href);
            if (state.device) url.searchParams.set('device', state.device);
            url.searchParams.set('period', state.period);
            if (state.period === 'date' && state.date) url.searchParams.set('date', state.date);
            else url.searchParams.delete('date');
            window.history.replaceState({}, '', url.toString());
        }

        document.querySelectorAll('.period-btn').forEach((btn) => {
            btn.addEventListener('click', () => {
                state.period = btn.dataset.period;
                syncFilterButtonsUI();
                updateBrowserQuery();
                fetchAndUpdate();
            });
        });

        const applyDateBtn = document.getElementById('apply-date-btn');
        const dateInput = document.getElementById('chart-date-input');
        if (applyDateBtn && dateInput) {
            applyDateBtn.addEventListener('click', () => {
                if (!dateInput.value) return;
                state.period = 'date';
                state.date = dateInput.value;
                syncFilterButtonsUI();
                updateBrowserQuery();
                fetchAndUpdate();
            });
        }

        const deviceSelect = document.getElementById('chart-device-select');
        if (deviceSelect) {
            deviceSelect.addEventListener('change', () => {
                state.device = deviceSelect.value;
                updateBrowserQuery();
                fetchAndUpdate();
            });
        }

        const manualRefreshBtn = document.getElementById('manual-refresh-btn');
        if (manualRefreshBtn) {
            manualRefreshBtn.addEventListener('click', fetchAndUpdate);
        }

        const toggleLiveBtn = document.getElementById('toggle-live-btn');
        if (toggleLiveBtn) {
            toggleLiveBtn.addEventListener('click', () => {
                state.liveEnabled = !state.liveEnabled;
                const dot = document.getElementById('live-indicator-dot');
                const txt = document.getElementById('live-indicator-text');
                if (state.liveEnabled) {
                    toggleLiveBtn.className = 'inline-flex items-center justify-center gap-2 min-h-[44px] sm:min-h-[40px] px-3.5 py-2 rounded-xl text-xs font-semibold bg-emerald-50 text-emerald-800 border border-emerald-200 hover:bg-emerald-100 transition';
                    dot.className = 'w-2 h-2 rounded-full bg-emerald-700';
                    txt.textContent = 'Auto-Update Aktif ({{ $refreshIntervalSec }} dtk)';
                    fetchAndUpdate();
                } else {
                    toggleLiveBtn.className = 'inline-flex items-center justify-center gap-2 min-h-[44px] sm:min-h-[40px] px-3.5 py-2 rounded-xl text-xs font-semibold bg-slate-100 text-slate-700 border border-slate-300 hover:bg-slate-200 transition';
                    dot.className = 'w-2 h-2 rounded-full bg-slate-500';
                    txt.textContent = 'Auto-Update Dijeda';
                }
            });
        }

        bootCharts();

        setInterval(() => {
            if (state.liveEnabled && state.period !== 'date') {
                fetchAndUpdate();
            }
        }, refreshMs);
    })();
</script>
