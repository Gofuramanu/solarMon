/**
 * ============================================================================
 * CONTROLLER DASHBOARD SOLARMONITOR - COBALT BLUE DONEZO EDITION
 * Mendukung 2 Mode Sumber Data:
 * 1. Supabase Cloud (PostgreSQL + RPC ESP32 + Realtime + 4s Polling Fallback)
 * 2. Simulasi Interaktif (untuk pengujian UI tanpa hardware)
 * ============================================================================
 */

const STORAGE_KEY = "solarmon_dashboard_settings_v1";
const MAX_REALTIME_POINTS = 25;

const defaultSettings = {
  mode: window.APP_CONFIG?.mode || "supabase",
  supabaseUrl: window.APP_CONFIG?.supabase?.url || "",
  supabaseAnonKey: window.APP_CONFIG?.supabase?.anonKey || "",
  deviceId: window.APP_CONFIG?.supabase?.deviceId || "ESP32_SOLAR_01",
  weatherLat: -7.7956,
  weatherLon: 110.3695,
  weatherLocationName: "Yogyakarta"
};

function createEmptyTelemetry() {
  return {
    voltage: 0,
    current_ma: 0,
    power_w: 0,
    energy_wh: 0,
    lux: 0,
    temperature: 0,
    ina_ok: false,
    bh_ok: false,
    temp_ok: false,
    uptime_seconds: 0,
    wifi_connected: false,
    last_seen_at: null
  };
}

const appState = {
  settings: loadSettings(),
  activePage: "overview",
  user: null,
  role: "viewer",
  lastOnlineState: false,
  autoSyncEnabled: true,
  searchQuery: "",
  telemetry: createEmptyTelemetry(),
  config: {
    panel_capacity_wp: 10.0,
    temp_threshold_warn: 45.0,
    temp_threshold_alarm: 60.0,
    low_lux_threshold: 500.0,
    offline_timeout_seconds: 30,
    log_interval_seconds: 10
  },
  realtimeSeries: [],
  recentLogs: [],
  historyLogs: [],
  filteredHistory: [],
  activityLogs: [],
  simPreset: "normal",
  weather: {
    loaded: false,
    loading: false,
    error: null,
    tempC: null,
    humidity: null,
    cloudCover: null,
    radiationWm2: null,
    windKmh: null,
    weatherCode: null,
    conditionText: "--",
    updatedAt: null
  }
};

let supabaseClient = null;
let supabaseChannels = [];
let supabasePollIntervalId = null;
let lastPushedSeenAt = null;
let simIntervalId = null;
let heartbeatTimerId = null;
let charts = {};

// ----------------------------------------------------------------------------
// HELPER UTILITAS
// ----------------------------------------------------------------------------
function loadSettings() {
  try {
    const raw = localStorage.getItem(STORAGE_KEY);
    return raw ? { ...defaultSettings, ...JSON.parse(raw) } : { ...defaultSettings };
  } catch {
    return { ...defaultSettings };
  }
}

function saveSettingsToStorage(nextSettings) {
  appState.settings = { ...appState.settings, ...nextSettings };
  localStorage.setItem(STORAGE_KEY, JSON.stringify(appState.settings));
}

function formatNum(val, decimals = 1) {
  const num = Number(val);
  return Number.isFinite(num) ? num.toFixed(decimals) : "--";
}

function formatSmartWatt(val) {
  const num = Number(val) || 0;
  return num > 0 && num < 0.1 ? num.toFixed(4) : num.toFixed(2);
}

function formatClockUptime(totalSec) {
  const sec = Math.max(0, Math.floor(Number(totalSec) || 0));
  const h = Math.floor(sec / 3600);
  const m = Math.floor((sec % 3600) / 60);
  const s = sec % 60;
  return `${String(h).padStart(2, "0")}:${String(m).padStart(2, "0")}:${String(s).padStart(2, "0")}`;
}

function formatTime(isoStr) {
  if (!isoStr) return "--";
  const d = new Date(isoStr);
  return Number.isNaN(d.getTime()) ? "--" : d.toLocaleTimeString("id-ID");
}

function formatDateTime(isoStr) {
  if (!isoStr) return "--";
  const d = new Date(isoStr);
  return Number.isNaN(d.getTime()) ? "--" : d.toLocaleString("id-ID");
}

function setText(id, value) {
  const el = document.getElementById(id);
  if (el) el.textContent = value;
}

function setStateBadge(id, isOn, labelOn = "READY", labelOff = "OFFLINE") {
  const el = document.getElementById(id);
  if (!el) return;
  el.textContent = isOn ? labelOn : labelOff;
  el.className = "pill-state " + (isOn ? "on" : "off");
}

function setQualityTag(id, label, level) {
  const el = document.getElementById(id);
  if (!el) return;
  el.textContent = label;
  el.className = "status-tag-soft" + (level && level !== "good" ? " " + level : "");
}

function getLightCategory(lux, lowThreshold = 500) {
  const val = Number(lux) || 0;
  if (val < 50) return "Gelap";
  if (val < lowThreshold) return "Redup";
  if (val < 15000) return "Berawan";
  if (val < 50000) return "Cerah";
  return "Terik";
}

function isDeviceOnline() {
  if (appState.settings.mode === "simulator") return true;
  const t = appState.telemetry;
  if (!t || !t.wifi_connected || !t.last_seen_at) return false;
  const lastMs = new Date(t.last_seen_at).getTime();
  if (!Number.isFinite(lastMs)) return false;
  const timeoutMs = (Number(appState.config.offline_timeout_seconds) || 30) * 1000;
  return Date.now() - lastMs <= timeoutMs;
}

function canAdminEdit() {
  if (appState.settings.mode === "simulator") return true;
  return Boolean(appState.user) && ["admin", "operator"].includes(appState.role);
}

// ----------------------------------------------------------------------------
// NAVIGASI 5 HALAMAN
// ----------------------------------------------------------------------------
function navigateTo(pageKey) {
  const target = document.getElementById(`page-${pageKey}`);
  if (!target) return;
  appState.activePage = pageKey;

  document.querySelectorAll(".page").forEach((el) => el.classList.remove("active"));
  target.classList.add("active");

  document.querySelectorAll(".nav-item, .mob-nav-item").forEach((btn) => {
    btn.classList.toggle("active", btn.dataset.page === pageKey);
  });

  document.getElementById("sidebar")?.classList.remove("open");
  document.getElementById("sidebarBackdrop")?.classList.remove("show");

  if (pageKey === "history") {
    loadHistoryData();
  }
}

// ----------------------------------------------------------------------------
// GRAFIK CHART.JS (COBALT BLUE PALETTE)
// ----------------------------------------------------------------------------
const CHART_PAL = {
  cobaltDeep: "#00288E",
  cobaltPrimary: "#1E40AF",
  cobaltMid: "#2563EB",
  cobaltLight: "#60A5FA",
  cobaltSoftFill: "rgba(30, 64, 175, 0.12)",
  skyAccent: "#0284C7",
  text: "#64748B",
  grid: "rgba(148, 163, 184, 0.18)"
};

function createLineChart(canvasId, datasets, yAxes = {}) {
  const ctx = document.getElementById(canvasId);
  if (!ctx || typeof window.Chart === "undefined") return null;

  return new window.Chart(ctx, {
    type: "line",
    data: { labels: [], datasets },
    options: {
      responsive: true,
      maintainAspectRatio: false,
      animation: { duration: 180 },
      interaction: { mode: "index", intersect: false },
      plugins: {
        legend: { labels: { color: CHART_PAL.text, boxWidth: 12, font: { family: "Plus Jakarta Sans", weight: "600" } } }
      },
      scales: {
        x: {
          ticks: { color: CHART_PAL.text, maxTicksLimit: 8 },
          grid: { display: false }
        },
        ...yAxes
      }
    }
  });
}

function initCharts() {
  if (typeof window.Chart === "undefined") return;

  // Overview Capsule Bar Chart (matching desain.jpg "Project Analytics")
  const ovCtx = document.getElementById("overviewBarChart");
  if (ovCtx) {
    charts.overviewBar = new window.Chart(ovCtx, {
      type: "bar",
      data: {
        labels: [],
        datasets: [
          {
            label: "Tegangan (V)",
            data: [],
            backgroundColor: (ctx) => {
              const idx = ctx.dataIndex;
              const len = ctx.dataset.data.length;
              if (idx === len - 1) return CHART_PAL.cobaltDeep;
              return idx % 2 === 0 ? CHART_PAL.cobaltPrimary : CHART_PAL.cobaltLight;
            },
            borderRadius: 14,
            borderSkipped: false,
            barPercentage: 0.62,
            categoryPercentage: 0.8
          }
        ]
      },
      options: {
        responsive: true,
        maintainAspectRatio: false,
        animation: { duration: 180 },
        plugins: {
          legend: { display: false },
          tooltip: {
            callbacks: {
              label: (item) => `${Number(item.raw).toFixed(2)} V`
            }
          }
        },
        scales: {
          x: {
            ticks: { color: CHART_PAL.text, font: { size: 11, weight: "600" } },
            grid: { display: false },
            border: { display: false }
          },
          y: {
            beginAtZero: true,
            ticks: { color: CHART_PAL.text, maxTicksLimit: 5 },
            grid: { color: CHART_PAL.grid },
            border: { display: false }
          }
        }
      }
    });
  }

  charts.elPower = createLineChart(
    "electricalPowerChart",
    [
      { label: "Daya (W)", data: [], borderColor: CHART_PAL.cobaltPrimary, backgroundColor: CHART_PAL.cobaltSoftFill, fill: true, tension: 0.35, yAxisID: "y" },
      { label: "Arus (mA)", data: [], borderColor: CHART_PAL.skyAccent, borderDash: [5, 4], tension: 0.35, yAxisID: "y1" }
    ],
    {
      y: { position: "left", ticks: { color: CHART_PAL.cobaltPrimary }, grid: { color: CHART_PAL.grid } },
      y1: { position: "right", ticks: { color: CHART_PAL.skyAccent }, grid: { drawOnChartArea: false } }
    }
  );

  charts.elVoltage = createLineChart(
    "electricalVoltageChart",
    [
      { label: "Tegangan (V)", data: [], borderColor: CHART_PAL.cobaltDeep, backgroundColor: CHART_PAL.cobaltSoftFill, fill: true, tension: 0.35, yAxisID: "y" }
    ],
    {
      y: { position: "left", min: 0, ticks: { color: CHART_PAL.cobaltDeep }, grid: { color: CHART_PAL.grid } }
    }
  );

  charts.env = createLineChart(
    "environmentChart",
    [
      { label: "Cahaya (Lux)", data: [], borderColor: CHART_PAL.cobaltPrimary, backgroundColor: CHART_PAL.cobaltSoftFill, fill: true, tension: 0.35, yAxisID: "y" },
      { label: "Suhu Panel (C)", data: [], borderColor: "#D97706", borderDash: [5, 4], tension: 0.35, yAxisID: "y1" }
    ],
    {
      y: { position: "left", min: 0, ticks: { color: CHART_PAL.cobaltPrimary }, grid: { color: CHART_PAL.grid } },
      y1: { position: "right", ticks: { color: "#D97706" }, grid: { drawOnChartArea: false } }
    }
  );

  charts.histPower = createLineChart(
    "historyPowerChart",
    [
      { label: "Daya (W)", data: [], borderColor: CHART_PAL.cobaltPrimary, tension: 0.3, yAxisID: "y" },
      { label: "Tegangan (V)", data: [], borderColor: CHART_PAL.skyAccent, borderDash: [4, 3], tension: 0.3, yAxisID: "y1" }
    ],
    {
      y: { position: "left", ticks: { color: CHART_PAL.cobaltPrimary }, grid: { color: CHART_PAL.grid } },
      y1: { position: "right", ticks: { color: CHART_PAL.skyAccent }, grid: { drawOnChartArea: false } }
    }
  );

  charts.histTemp = createLineChart(
    "historyTempChart",
    [
      { label: "Cahaya (Lux)", data: [], borderColor: CHART_PAL.cobaltPrimary, tension: 0.3, yAxisID: "y" },
      { label: "Suhu Panel (C)", data: [], borderColor: "#D97706", borderDash: [4, 3], tension: 0.3, yAxisID: "y1" }
    ],
    {
      y: { position: "left", min: 0, ticks: { color: CHART_PAL.cobaltPrimary }, grid: { color: CHART_PAL.grid } },
      y1: { position: "right", ticks: { color: "#D97706" }, grid: { drawOnChartArea: false } }
    }
  );
}

function updateRealtimeCharts() {
  const labels = appState.realtimeSeries.map((p) => p.label);

  if (charts.overviewBar) {
    const barSlice = appState.realtimeSeries.slice(-8);
    charts.overviewBar.data.labels = barSlice.map((p) => p.label);
    charts.overviewBar.data.datasets[0].data = barSlice.map((p) => p.voltage);
    charts.overviewBar.update("none");
  }

  if (charts.elPower) {
    charts.elPower.data.labels = labels;
    charts.elPower.data.datasets[0].data = appState.realtimeSeries.map((p) => p.power_w);
    charts.elPower.data.datasets[1].data = appState.realtimeSeries.map((p) => p.current_ma);
    charts.elPower.update("none");
  }

  if (charts.elVoltage) {
    charts.elVoltage.data.labels = labels;
    charts.elVoltage.data.datasets[0].data = appState.realtimeSeries.map((p) => p.voltage);
    charts.elVoltage.update("none");
  }

  if (charts.env) {
    charts.env.data.labels = labels;
    charts.env.data.datasets[0].data = appState.realtimeSeries.map((p) => p.lux);
    charts.env.data.datasets[1].data = appState.realtimeSeries.map((p) => p.temperature);
    charts.env.update("none");
  }
}

function pushRealtimePoint(t) {
  const seenKey = t.last_seen_at || t.recorded_at;
  if (appState.settings.mode === "supabase" && seenKey && seenKey === lastPushedSeenAt) {
    return;
  }
  if (seenKey) lastPushedSeenAt = seenKey;

  const point = {
    label: formatTime(seenKey || new Date().toISOString()),
    recorded_at: seenKey || new Date().toISOString(),
    voltage: Number(t.voltage) || 0,
    current_ma: Number(t.current_ma) || 0,
    power_w: Number(t.power_w) || 0,
    lux: Number(t.lux) || 0,
    temperature: Number(t.temperature) || 0
  };

  appState.realtimeSeries.push(point);
  if (appState.realtimeSeries.length > MAX_REALTIME_POINTS) {
    appState.realtimeSeries.shift();
  }

  appState.recentLogs.unshift(point);
  if (appState.recentLogs.length > 25) {
    appState.recentLogs.pop();
  }

  updateRealtimeCharts();
}

// ----------------------------------------------------------------------------
// RENDER SELURUH UI DARI STATE
// ----------------------------------------------------------------------------
function renderAll() {
  const online = isDeviceOnline();
  appState.lastOnlineState = online;
  const hasData = online || Boolean(appState.telemetry && appState.telemetry.last_seen_at);
  const t = hasData ? appState.telemetry : createEmptyTelemetry();
  const c = appState.config;

  setText("sidebarDeviceId", appState.settings.deviceId);
  renderTopbarBadges();

  const readyCount = hasData ? [t.ina_ok, t.bh_ok, t.temp_ok].filter(Boolean).length : 0;
  setText("navSensorCount", `${readyCount}/3`);
  setText("ovBusHealth", `${readyCount}/3 Ready`);

  // --- ROW 1: 4 KPI CARDS ---
  const pw = hasData && t.ina_ok ? Number(t.power_w) || 0 : 0;
  const v = hasData && t.ina_ok ? Number(t.voltage) || 0 : 0;
  const ma = hasData && t.ina_ok ? Number(t.current_ma) || 0 : 0;
  const wh = hasData ? Number(t.energy_wh) || 0 : 0;
  const capWp = Math.max(1, Number(c.panel_capacity_wp) || 10);

  setText("ovPower", hasData && t.ina_ok ? formatSmartWatt(pw) : "--");
  setText("ovPowerMwBadge", hasData && t.ina_ok ? `${formatNum(pw * 1000, 2)} mW` : "-- mW");

  setText("ovVoltage", hasData && t.ina_ok ? formatNum(v, 2) : "--");
  setText("ovWifiStatus", online && t.wifi_connected ? "Node terhubung live" : hasData ? `Terakhir ${formatTime(t.last_seen_at)}` : "Menunggu node");

  setText("ovCurrent", hasData && t.ina_ok ? formatNum(ma, 2) : "--");
  setText("ovCurrentAmpBadge", hasData && t.ina_ok ? `${formatNum(ma / 1000, 4)} A` : "-- A");

  const lightCat = hasData && t.bh_ok ? getLightCategory(t.lux, c.low_lux_threshold) : "--";
  setText("ovLux", hasData && t.bh_ok ? Math.round(t.lux).toLocaleString("id-ID") : "--");
  setText("ovSolarCond", lightCat);

  // --- ROW 2: ANALYTICS, THERMAL & MODULES ---
  setText("ovLastSeen", hasData ? `${online ? "Sync" : "Terakhir"}: ${formatTime(t.last_seen_at)}` : "Sync: --");

  const tempVal = Number(t.temperature) || 0;
  const isOverheat = hasData && t.temp_ok && tempVal >= c.temp_threshold_alarm;
  const isWarm = hasData && t.temp_ok && tempVal >= c.temp_threshold_warn;
  setText("ovTempPanel", hasData && t.temp_ok ? formatNum(t.temperature, 1) : "--");
  setText("ovTempHint", `Sensor DS18B20 (GPIO 4) - Alarm >= ${formatNum(c.temp_threshold_alarm, 0)} C`);
  if (!hasData) setQualityTag("ovThermalHealth", "Offline", "neutral");
  else if (!t.temp_ok) setQualityTag("ovThermalHealth", "Error", "bad");
  else if (isOverheat) setQualityTag("ovThermalHealth", "Overheat", "bad");
  else if (isWarm) setQualityTag("ovThermalHealth", "Hangat", "warn");
  else setQualityTag("ovThermalHealth", "Normal", "good");

  setStateBadge("ovInaBadge", hasData && t.ina_ok, "READY", hasData ? "ERROR" : "OFFLINE");
  setStateBadge("ovBhBadge", hasData && t.bh_ok, "READY", hasData ? "ERROR" : "OFFLINE");
  setStateBadge("ovDsBadge", hasData && t.temp_ok, "READY", hasData ? "ERROR" : "OFFLINE");
  setStateBadge("ovLcdBadge", online, "ACTIVE", hasData ? "STANDBY" : "OFFLINE");

  // --- ROW 3: SEMI-CIRCLE ENERGY GAUGE & UPTIME TRACKER ---
  setText("ovEnergy", hasData ? formatSmartWatt(wh) : "0.0000");
  setText("ovTargetCapLabel", `${capWp} Wp`);
  const gaugeArc = document.getElementById("ovEnergyGaugeArc");
  if (gaugeArc) {
    const targetWh = Math.max(1, capWp * 5);
    const pct = wh > 0 ? Math.min(1, Math.max(0.08, wh / targetWh)) : 0.04;
    const totalLen = 276.5;
    gaugeArc.setAttribute("stroke-dashoffset", (totalLen * (1 - pct)).toFixed(1));
  }

  setText("ovUptime", hasData ? formatClockUptime(t.uptime_seconds) : "00:00:00");
  setText("ovLastSeenSmall", appState.autoSyncEnabled ? "Auto-Sync Aktif (4 dtk)" : "Auto-Sync Dijeda");

  // --- HALAMAN 2: ELECTRICAL TELEMETRY ---
  const loadPct = hasData ? Math.min(100, Math.max(0, (pw / capWp) * 100)) : 0;
  setText("elPower", hasData && t.ina_ok ? formatSmartWatt(pw) : "--");
  setText("elLoadPct", `${loadPct.toFixed(2)}% DARI ${capWp}Wp`);
  const loadBar = document.getElementById("elLoadBar");
  if (loadBar) loadBar.style.width = hasData ? `${Math.max(pw > 0 ? 4 : 0, loadPct)}%` : "0%";
  setText("elPowerMw", hasData && t.ina_ok ? `${formatNum(pw * 1000, 2)} mW` : "-- mW");
  setText("elEnergyKwh", hasData ? `${formatNum(wh / 1000, 5)} kWh` : "-- kWh");

  setText("elVoltage", hasData && t.ina_ok ? formatNum(v, 2) : "--");
  setText("elCurrent", hasData && t.ina_ok ? formatNum(ma, 2) : "--");
  setText("elCurrentAmp", hasData && t.ina_ok ? `${formatNum(ma / 1000, 4)} A` : "-- A");
  setText("elEnergy", hasData ? formatSmartWatt(wh) : "--");

  if (!hasData) setQualityTag("elVoltageQuality", "Offline", "neutral");
  else if (!t.ina_ok) setQualityTag("elVoltageQuality", "Sensor Err", "bad");
  else if (v < 1.0) setQualityTag("elVoltageQuality", "0 V / Gelap", "warn");
  else setQualityTag("elVoltageQuality", online ? "Aktif" : "Terakhir", "good");

  // --- HALAMAN 3: SOLAR & THERMAL ---
  setText("envLux", hasData && t.bh_ok ? Math.round(t.lux).toLocaleString("id-ID") : "--");
  setText("envTempPanel", hasData && t.temp_ok ? formatNum(t.temperature, 1) : "--");
  setText("envLightCategory", lightCat === "--" ? "--" : `Kondisi ${lightCat} (${Math.round(t.lux)} Lux)`);
  setText("envWarnThreshold", formatNum(c.temp_threshold_warn, 1));
  setText("envAlarmThreshold", formatNum(c.temp_threshold_alarm, 1));

  if (!hasData) setQualityTag("envPanelZone", "Offline", "neutral");
  else if (!t.temp_ok) setQualityTag("envPanelZone", "DS18B20 Err", "bad");
  else if (isOverheat) setQualityTag("envPanelZone", "OVERHEAT", "bad");
  else if (isWarm) setQualityTag("envPanelZone", "HANGAT", "warn");
  else setQualityTag("envPanelZone", "NORMAL", "good");

  renderOverviewRecentLogs();
  renderActivityFeed();
  renderWeatherUI();
  evaluateAlerts();
}

function describeWmoWeather(code) {
  const c = Number(code);
  if (c === 0) return "Cerah";
  if (c === 1) return "Umumnya Cerah";
  if (c === 2) return "Cerah Berawan";
  if (c === 3) return "Mendung";
  if (c === 45 || c === 48) return "Berkabut";
  if (c >= 51 && c <= 57) return "Gerimis";
  if (c >= 61 && c <= 65) return "Hujan";
  if (c >= 80 && c <= 82) return "Hujan Lokal";
  if (c >= 95) return "Badai Petir";
  return "Berawan";
}

async function fetchWeatherData() {
  const lat = Number(appState.settings.weatherLat);
  const lon = Number(appState.settings.weatherLon);
  if (!Number.isFinite(lat) || !Number.isFinite(lon)) return;

  appState.weather.loading = true;
  appState.weather.error = null;
  renderWeatherUI();

  try {
    const url =
      `https://api.open-meteo.com/v1/forecast?latitude=${encodeURIComponent(lat)}&longitude=${encodeURIComponent(lon)}` +
      `&current=temperature_2m,relative_humidity_2m,cloud_cover,weather_code,shortwave_radiation,wind_speed_10m&timezone=auto`;
    const res = await fetch(url);
    if (!res.ok) throw new Error(`HTTP ${res.status}`);
    const data = await res.json();
    const cur = data?.current || {};

    appState.weather = {
      loaded: true,
      loading: false,
      error: null,
      tempC: Number(cur.temperature_2m),
      humidity: Number(cur.relative_humidity_2m),
      cloudCover: Number(cur.cloud_cover),
      radiationWm2: Number(cur.shortwave_radiation),
      windKmh: Number(cur.wind_speed_10m),
      weatherCode: cur.weather_code,
      conditionText: describeWmoWeather(cur.weather_code),
      updatedAt: new Date()
    };
  } catch (err) {
    appState.weather.loading = false;
    appState.weather.error = err?.message || "Gagal memuat cuaca";
  }

  renderWeatherUI();
}

function renderWeatherUI() {
  const w = appState.weather;
  const s = appState.settings;
  const locName = s.weatherLocationName || "Lokasi Panel";
  setText("wxLocationLabel", `${locName} (${Number(s.weatherLat).toFixed(4)}, ${Number(s.weatherLon).toFixed(4)})`);

  if (w.loading && !w.loaded) {
    setText("ovWeatherMini", "Cuaca Luar: Memuat...");
    setText("wxCondition", "Memuat...");
    return;
  }

  if (w.error && !w.loaded) {
    setText("ovWeatherMini", "Cuaca Luar: Offline");
    setText("wxCondition", "Gagal memuat");
    setText("wxValidationBadge", "API Offline");
    return;
  }

  if (!w.loaded) return;

  setText("ovWeatherMini", `Udara Luar: ${formatNum(w.tempC, 1)} C - ${w.conditionText}`);
  setText("wxCondition", w.conditionText);
  setText("wxAmbientTemp", `${formatNum(w.tempC, 1)} °C`);
  setText("wxCloudCover", `${Math.round(w.cloudCover)} %`);
  setText("wxRadiation", `${Math.round(w.radiationWm2)} W/m²`);
  setText("wxHumidity", `${Math.round(w.humidity)}%`);
  setText("wxWind", `${formatNum(w.windKmh, 1)} km/h`);
  setText("wxUpdatedAt", w.updatedAt ? `Update: ${formatTime(w.updatedAt)}` : "Update: --");

  const t = appState.telemetry;
  const hasData = isDeviceOnline() || Boolean(t && t.last_seen_at);

  // Perbandingan suhu panel (DS18B20) vs suhu udara luar (Open-Meteo)
  if (hasData && t.temp_ok && Number.isFinite(w.tempC)) {
    const deltaT = Number(t.temperature) - w.tempC;
    const sign = deltaT >= 0 ? "+" : "";
    setText("wxDeltaTemp", `${sign}${deltaT.toFixed(1)} °C`);
  } else {
    setText("wxDeltaTemp", "--");
  }

  // Validasi cahaya BH1750 vs tutupan awan & radiasi Open-Meteo
  if (!hasData || !t.bh_ok) {
    setText("wxLightInsight", "Menunggu sensor");
    setText("wxValidationBadge", "Standby");
    setText("wxInsightSummary", "Menunggu pembacaan aktif BH1750 & DS18B20 untuk dibandingkan dengan data cuaca luar.");
    return;
  }

  const luxVal = Number(t.lux) || 0;
  const rad = Number(w.radiationWm2) || 0;
  const clouds = Number(w.cloudCover) || 0;

  if (rad < 20) {
    setText("wxLightInsight", "Malam / Gelap");
    setText("wxValidationBadge", "Selaras (Malam)");
    setText("wxInsightSummary", `Radiasi surya eksternal ${Math.round(rad)} W/m2 (malam atau matahari terbenam), selaras dengan BH1750 (${Math.round(luxVal)} Lux).`);
  } else if (luxVal < 500 && clouds < 40 && rad >= 200) {
    setText("wxLightInsight", "Potensi Bayangan");
    setText("wxValidationBadge", "Cek Penghalang");
    setText("wxInsightSummary", `Langit cukup cerah (awan ${Math.round(clouds)}%, radiasi ${Math.round(rad)} W/m2), tetapi BH1750 hanya terbaca ${Math.round(luxVal)} Lux. Cek kemungkinan bayangan atau lokasi sensor indoor.`);
  } else if (luxVal < 2000 && clouds >= 60) {
    setText("wxLightInsight", "Selaras (Mendung)");
    setText("wxValidationBadge", "Selaras");
    setText("wxInsightSummary", `Intensitas rendah (${Math.round(luxVal)} Lux) selaras dengan tutupan awan ${Math.round(clouds)}% (${w.conditionText}).`);
  } else {
    setText("wxLightInsight", "Selaras (Optimal)");
    setText("wxValidationBadge", "Selaras");
    setText("wxInsightSummary", `Pembacaan BH1750 (${Math.round(luxVal).toLocaleString("id-ID")} Lux) sesuai dengan radiasi luar ${Math.round(rad)} W/m2 dan awan ${Math.round(clouds)}%.`);
  }
}

function renderTopbarBadges() {
  const modeMap = { simulator: "Simulasi", supabase: "Supabase" };
  setText("backendBadge", modeMap[appState.settings.mode] || "Supabase");

  if (appState.settings.mode === "simulator") {
    setText("userRoleLabel", "Demo");
  } else if (appState.user) {
    setText("userRoleLabel", appState.role.toUpperCase());
  } else {
    setText("userRoleLabel", "Publik");
  }

  updateHeartbeatBadge();
}

function updateHeartbeatBadge() {
  const badge = document.getElementById("heartbeatBadge");
  const textEl = document.getElementById("heartbeatText");
  if (!badge) return;

  if (isDeviceOnline()) {
    badge.className = "status-pill online";
    if (textEl) textEl.textContent = "ESP32 ONLINE";
  } else {
    badge.className = "status-pill offline";
    if (textEl) textEl.textContent = "ESP32 OFFLINE";
  }
}

function evaluateAlerts() {
  const banner = document.getElementById("alertBanner");
  const iconEl = document.getElementById("alertBannerIcon");
  const textEl = document.getElementById("alertBannerText");
  if (!banner || !iconEl || !textEl) return;

  const online = isDeviceOnline();
  const t = appState.telemetry;
  const hasData = online || Boolean(t && t.last_seen_at);
  const alarmTh = Number(appState.config.temp_threshold_alarm) || 60;

  if (hasData && t.temp_ok && t.temperature >= alarmTh) {
    banner.className = "alert-banner danger";
    iconEl.textContent = "OVERHEAT";
    textEl.textContent = `Suhu panel mencapai ${t.temperature.toFixed(1)} C (melewati batas alarm ${alarmTh} C).`;
  } else if (hasData && (!t.ina_ok || !t.bh_ok || !t.temp_ok)) {
    banner.className = "alert-banner danger";
    iconEl.textContent = "SENSOR";
    const errs = [!t.ina_ok && "INA219", !t.bh_ok && "BH1750", !t.temp_ok && "DS18B20"].filter(Boolean).join(", ");
    textEl.textContent = `Ada modul sensor tidak terbaca (${errs}). Periksa koneksi kabel ESP32.`;
  } else if (!online && appState.settings.mode !== "simulator") {
    banner.className = "alert-banner";
    iconEl.textContent = "STANDBY";
    textEl.textContent = hasData
      ? `Menampilkan pembacaan terakhir (${formatTime(t.last_seen_at)}). Menunggu kirim ulang dari ESP32.`
      : "Node ESP32 belum mengirim data terbaru. Menunggu sinkronisasi dari hardware.";
  } else {
    banner.className = "alert-banner hidden";
  }
}

function renderOverviewRecentLogs() {
  const feedEl = document.getElementById("overviewActivityList");
  if (!feedEl) return;
  feedEl.replaceChildren();

  const q = appState.searchQuery.trim().toLowerCase();
  const list = (appState.recentLogs || []).filter((item) => {
    if (!q) return true;
    const hay = `${item.label} ${item.voltage} ${item.current_ma} ${item.lux} ${item.temperature}`.toLowerCase();
    return hay.includes(q);
  });

  if (list.length === 0) {
    const li = document.createElement("li");
    li.className = "empty-note";
    li.textContent = q ? "Tidak ada log cocok dengan pencarian." : "Menunggu aliran data sensor...";
    feedEl.appendChild(li);
    return;
  }

  const warnTh = Number(appState.config.temp_threshold_warn) || 45;
  const alarmTh = Number(appState.config.temp_threshold_alarm) || 60;

  list.slice(0, 4).forEach((item, idx) => {
    const li = document.createElement("li");
    li.className = "recent-log-item";

    const left = document.createElement("div");
    left.className = "recent-log-left";

    const av = document.createElement("div");
    av.className = "log-avatar";
    av.textContent = `#${list.length - idx}`;

    const txt = document.createElement("div");
    txt.className = "log-text";
    const strong = document.createElement("strong");
    strong.textContent = `${formatNum(item.voltage, 2)} V  |  ${formatNum(item.current_ma, 2)} mA  |  ${formatSmartWatt(item.power_w)} W`;
    const sub = document.createElement("span");
    sub.textContent = `${item.label} WIB  -  ${Math.round(item.lux)} Lux  -  ${formatNum(item.temperature, 1)} C`;
    txt.append(strong, sub);

    left.append(av, txt);

    const badge = document.createElement("span");
    const isAlarm = item.temperature >= alarmTh;
    const isWarn = item.temperature >= warnTh;
    badge.className = "status-tag-soft" + (isAlarm ? " bad" : isWarn ? " warn" : "");
    badge.textContent = isAlarm ? "Overheat" : isWarn ? "Hangat" : "Normal";

    li.append(left, badge);
    feedEl.appendChild(li);
  });
}

function renderActivityFeed() {
  const tableBody = document.getElementById("historyActivityBody");
  if (!tableBody) return;
  const logs = appState.activityLogs || [];
  tableBody.replaceChildren();

  if (logs.length === 0) {
    const tr = document.createElement("tr");
    const td = document.createElement("td");
    td.colSpan = 5;
    td.className = "empty-note";
    td.textContent = "Belum ada catatan aktivitas.";
    tr.appendChild(td);
    tableBody.appendChild(tr);
    return;
  }

  logs.slice(0, 40).forEach((item) => {
    const tr = document.createElement("tr");
    [
      formatDateTime(item.created_at),
      item.actor_type,
      item.actor_name,
      item.event_type,
      item.description
    ].forEach((val) => {
      const td = document.createElement("td");
      td.textContent = val || "--";
      tr.appendChild(td);
    });
    tableBody.appendChild(tr);
  });
}

// ----------------------------------------------------------------------------
// MODE 1: SIMULATOR FISIKA PANEL SURYA
// ----------------------------------------------------------------------------
function seedSimulatorHistory() {
  const now = Date.now();
  const points = [];
  let energyAcc = 18.4;

  for (let i = 47; i >= 0; i--) {
    const ts = new Date(now - i * 30 * 60 * 1000);
    const hour = ts.getHours() + ts.getMinutes() / 60;
    const sunFactor = Math.max(0, Math.sin(((hour - 6) / 12) * Math.PI));
    const lux = hour >= 6 && hour <= 18 ? Math.round(1200 + sunFactor * 68000 + Math.random() * 2500) : Math.round(Math.random() * 15);
    const voltage = sunFactor > 0.05 ? Number((14.8 + sunFactor * 4.2 + (Math.random() - 0.5) * 0.3).toFixed(2)) : 0.12;
    const current_ma = sunFactor > 0.05 ? Number((40 + sunFactor * 460 + Math.random() * 20).toFixed(2)) : 0.0;
    const power_w = Number(((voltage * current_ma) / 1000).toFixed(4));
    energyAcc += power_w * 0.5;
    const temperature = Number((26.5 + sunFactor * 21.0 + (Math.random() - 0.5) * 0.8).toFixed(1));

    points.push({
      recorded_at: ts.toISOString(),
      voltage,
      current_ma,
      power_w,
      energy_wh: Number(energyAcc.toFixed(3)),
      lux,
      temperature
    });
  }

  appState.historyLogs = points;
  appState.telemetry.energy_wh = Number(energyAcc.toFixed(3));
  appState.activityLogs = [
    {
      actor_type: "SYSTEM",
      actor_name: "Simulator",
      event_type: "BOOT",
      description: "Mode Simulasi SolarMonitor aktif.",
      created_at: new Date().toISOString()
    }
  ];
}

function evaluateSimulatorPhysics(dtSec) {
  const t = appState.telemetry;
  t.uptime_seconds += dtSec;
  t.wifi_connected = true;
  t.ina_ok = true;
  t.bh_ok = true;
  t.temp_ok = true;
  t.last_seen_at = new Date().toISOString();

  let targetLux = 38500;
  let targetTemp = 41.5;

  if (appState.simPreset === "dark") {
    targetLux = 140;
    targetTemp = 27.5;
  } else if (appState.simPreset === "peak") {
    targetLux = 88500;
    targetTemp = 62.5;
  }

  const noiseLux = targetLux + (Math.random() - 0.5) * (targetLux * 0.04);
  t.lux = Math.max(0, Math.round(t.lux + (noiseLux - t.lux) * 0.3));
  t.temperature = Number((t.temperature + (targetTemp - t.temperature) * 0.2).toFixed(1));

  const luxRatio = Math.min(1, t.lux / 80000);
  t.voltage = t.lux > 50 ? Number((3.8 + Math.pow(luxRatio, 0.3) * 1.4 + (Math.random() - 0.5) * 0.08).toFixed(2)) : 0.15;
  t.current_ma = t.lux > 50 ? Number((12 + luxRatio * 340 + (Math.random() - 0.5) * 6).toFixed(2)) : 0.1;
  t.power_w = Number(((t.voltage * t.current_ma) / 1000).toFixed(4));
  t.energy_wh = Number((t.energy_wh + t.power_w * (dtSec / 3600)).toFixed(4));
}

function startSimulatorMode() {
  stopActiveConnections();
  appState.role = "admin";
  appState.recentLogs = [];
  appState.telemetry = {
    ...createEmptyTelemetry(),
    lux: 38000,
    temperature: 40.5,
    ina_ok: true,
    bh_ok: true,
    temp_ok: true,
    wifi_connected: true,
    last_seen_at: new Date().toISOString()
  };

  if (appState.historyLogs.length === 0) seedSimulatorHistory();

  evaluateSimulatorPhysics(2);
  pushRealtimePoint(appState.telemetry);
  renderAll();
  if (appState.activePage === "history") loadHistoryData();

  simIntervalId = setInterval(() => {
    if (!appState.autoSyncEnabled) return;
    evaluateSimulatorPhysics(2);
    pushRealtimePoint(appState.telemetry);
    renderAll();
  }, 2000);
}

// ----------------------------------------------------------------------------
// MODE 2: SUPABASE CLOUD
// ----------------------------------------------------------------------------
async function startSupabaseMode() {
  stopActiveConnections();
  lastPushedSeenAt = null;
  appState.telemetry = createEmptyTelemetry();
  appState.realtimeSeries = [];
  appState.recentLogs = [];
  updateRealtimeCharts();
  renderAll();

  const { supabaseUrl, supabaseAnonKey } = appState.settings;
  if (!supabaseUrl || !supabaseAnonKey || supabaseUrl.includes("PROJECT_ID_ANDA")) {
    navigateTo("settings");
    renderAll();
    return;
  }

  try {
    const { createClient } = await import("https://cdn.jsdelivr.net/npm/@supabase/supabase-js@2/+esm");
    supabaseClient = createClient(supabaseUrl, supabaseAnonKey);

    const { data: sessionData } = await supabaseClient.auth.getSession();
    if (sessionData?.session?.user) {
      await handleSupabaseUser(sessionData.session.user);
    } else {
      appState.user = null;
      appState.role = "viewer";
      await fetchSupabaseInitialState(true);
      subscribeSupabaseRealtime();
      startSupabasePolling();
      updateAuthUI();
    }

    supabaseClient.auth.onAuthStateChange(async (_event, session) => {
      if (session?.user) {
        await handleSupabaseUser(session.user);
      } else {
        appState.user = null;
        appState.role = "viewer";
        updateAuthUI();
        renderAll();
      }
    });
  } catch (err) {
    console.error("Gagal memuat Supabase:", err);
    alert("Gagal menghubungkan ke Supabase: " + err.message);
  }
}

async function handleSupabaseUser(user) {
  appState.user = user;
  const { data: roleRow } = await supabaseClient
    .from("user_roles")
    .select("role")
    .eq("user_id", user.id)
    .maybeSingle();

  appState.role = roleRow?.role || "viewer";
  await fetchSupabaseInitialState(true);
  subscribeSupabaseRealtime();
  startSupabasePolling();
  renderAll();
  updateAuthUI();
}

function startSupabasePolling() {
  if (supabasePollIntervalId) clearInterval(supabasePollIntervalId);
  supabasePollIntervalId = setInterval(() => {
    if (appState.autoSyncEnabled) {
      fetchSupabaseInitialState(false);
    }
  }, 4000);
}

async function fetchSupabaseInitialState(includeSeries = false) {
  if (!supabaseClient) return;
  const deviceId = appState.settings.deviceId;

  const queries = [
    supabaseClient.from("solar_state").select("*").eq("device_id", deviceId).maybeSingle(),
    supabaseClient.from("solar_config").select("*").eq("device_id", deviceId).maybeSingle(),
    supabaseClient
      .from("activity_logs")
      .select("*")
      .eq("device_id", deviceId)
      .order("created_at", { ascending: false })
      .limit(30)
  ];

  if (includeSeries) {
    queries.push(
      supabaseClient
        .from("telemetry_logs")
        .select("*")
        .eq("device_id", deviceId)
        .order("recorded_at", { ascending: false })
        .limit(MAX_REALTIME_POINTS)
    );
  }

  const [stateRes, cfgRes, actRes, logsRes] = await Promise.all(queries);

  if (cfgRes.data) {
    appState.config = cfgRes.data;
    syncThresholdInputs();
  }
  if (includeSeries && logsRes?.data && logsRes.data.length > 0) {
    const recent = [...logsRes.data].reverse();
    appState.realtimeSeries = recent.map((r) => ({
      label: formatTime(r.recorded_at),
      recorded_at: r.recorded_at,
      voltage: Number(r.voltage) || 0,
      current_ma: Number(r.current_ma) || 0,
      power_w: Number(r.power_w) || 0,
      lux: Number(r.lux) || 0,
      temperature: Number(r.temperature) || 0
    }));
    appState.recentLogs = [...appState.realtimeSeries].reverse();
    lastPushedSeenAt = recent[recent.length - 1].recorded_at;
    updateRealtimeCharts();
  }
  if (stateRes.data) {
    appState.telemetry = stateRes.data;
    if (stateRes.data.last_seen_at) {
      pushRealtimePoint(stateRes.data);
    }
  } else {
    appState.telemetry = createEmptyTelemetry();
  }
  if (actRes.data) {
    appState.activityLogs = actRes.data;
  }
  renderAll();
}

function subscribeSupabaseRealtime() {
  unsubscribeSupabaseRealtime();
  if (!supabaseClient) return;
  const deviceId = appState.settings.deviceId;

  const ch = supabaseClient
    .channel(`solar-${deviceId}`)
    .on(
      "postgres_changes",
      { event: "*", schema: "public", table: "solar_state", filter: `device_id=eq.${deviceId}` },
      (payload) => {
        if (payload.new && appState.autoSyncEnabled) {
          appState.telemetry = payload.new;
          pushRealtimePoint(payload.new);
          renderAll();
        }
      }
    )
    .on(
      "postgres_changes",
      { event: "*", schema: "public", table: "solar_config", filter: `device_id=eq.${deviceId}` },
      (payload) => {
        if (payload.new) {
          appState.config = payload.new;
          syncThresholdInputs();
          renderAll();
        }
      }
    )
    .on(
      "postgres_changes",
      { event: "INSERT", schema: "public", table: "activity_logs", filter: `device_id=eq.${deviceId}` },
      (payload) => {
        if (payload.new) {
          appState.activityLogs.unshift(payload.new);
          renderActivityFeed();
        }
      }
    )
    .subscribe();

  supabaseChannels.push(ch);
}

function unsubscribeSupabaseRealtime() {
  if (supabaseClient && supabaseChannels.length > 0) {
    supabaseChannels.forEach((ch) => supabaseClient.removeChannel(ch));
  }
  supabaseChannels = [];
}

function stopActiveConnections() {
  if (simIntervalId) {
    clearInterval(simIntervalId);
    simIntervalId = null;
  }
  if (supabasePollIntervalId) {
    clearInterval(supabasePollIntervalId);
    supabasePollIntervalId = null;
  }
  unsubscribeSupabaseRealtime();
}

// ----------------------------------------------------------------------------
// HALAMAN 4: HISTORI, ANALITIK, TABEL & EXPORT CSV
// ----------------------------------------------------------------------------
async function loadHistoryData() {
  const range = document.getElementById("historyRangeSelect")?.value || "24h";
  const now = new Date();
  let start = new Date(now.getTime() - 24 * 3600 * 1000);
  let end = now;

  if (range === "1h") start = new Date(now.getTime() - 1 * 3600 * 1000);
  else if (range === "7d") start = new Date(now.getTime() - 7 * 24 * 3600 * 1000);
  else if (range === "30d") start = new Date(now.getTime() - 30 * 24 * 3600 * 1000);
  else if (range === "custom") {
    const sVal = document.getElementById("historyStartDate")?.value;
    const eVal = document.getElementById("historyEndDate")?.value;
    if (sVal) start = new Date(sVal + "T00:00:00");
    if (eVal) end = new Date(eVal + "T23:59:59");
  }

  let rows = [];
  if (appState.settings.mode === "supabase" && supabaseClient) {
    const { data, error } = await supabaseClient
      .from("telemetry_logs")
      .select("*")
      .eq("device_id", appState.settings.deviceId)
      .gte("recorded_at", start.toISOString())
      .lte("recorded_at", end.toISOString())
      .order("recorded_at", { ascending: true })
      .limit(1000);

    if (!error && data) rows = data;
  } else {
    rows = appState.historyLogs.filter((r) => {
      const ts = new Date(r.recorded_at).getTime();
      return ts >= start.getTime() && ts <= end.getTime();
    });
  }

  appState.filteredHistory = rows;
  renderHistoryAnalytics(rows);
  renderHistoryTable(rows);
  return rows;
}

function renderHistoryAnalytics(rows) {
  if (!rows || rows.length === 0) {
    setText("histPeakPower", "0");
    setText("histDeltaEnergy", "0.00");
    setText("histAvgLux", "--");
    setText("histAvgTemp", "--");
  } else {
    const peakPower = Math.max(...rows.map((r) => Number(r.power_w) || 0));
    const avgLux = rows.reduce((sum, r) => sum + (Number(r.lux) || 0), 0) / rows.length;
    const avgTemp = rows.reduce((sum, r) => sum + (Number(r.temperature) || 0), 0) / rows.length;
    const firstE = Number(rows[0].energy_wh) || 0;
    const lastE = Number(rows[rows.length - 1].energy_wh) || 0;
    const deltaE = Math.max(0, lastE - firstE);

    setText("histPeakPower", formatSmartWatt(peakPower));
    setText("histDeltaEnergy", formatSmartWatt(deltaE));
    setText("histAvgLux", Math.round(avgLux).toLocaleString("id-ID"));
    setText("histAvgTemp", avgTemp.toFixed(1));
  }

  const labels = rows.map((r) => formatDateTime(r.recorded_at));
  if (charts.histPower) {
    charts.histPower.data.labels = labels;
    charts.histPower.data.datasets[0].data = rows.map((r) => r.power_w);
    charts.histPower.data.datasets[1].data = rows.map((r) => r.voltage);
    charts.histPower.update();
  }
  if (charts.histTemp) {
    charts.histTemp.data.labels = labels;
    charts.histTemp.data.datasets[0].data = rows.map((r) => r.lux);
    charts.histTemp.data.datasets[1].data = rows.map((r) => r.temperature);
    charts.histTemp.update();
  }
}

function renderHistoryTable(rows) {
  const tbody = document.getElementById("historyTelemetryBody");
  if (!tbody) return;
  tbody.replaceChildren();

  if (!rows || rows.length === 0) {
    const tr = document.createElement("tr");
    const td = document.createElement("td");
    td.colSpan = 6;
    td.className = "empty-note";
    td.textContent = "Belum ada data logging pada rentang waktu ini.";
    tr.appendChild(td);
    tbody.appendChild(tr);
    return;
  }

  rows.slice(-50).reverse().forEach((r) => {
    const tr = document.createElement("tr");
    [
      formatDateTime(r.recorded_at),
      formatNum(r.voltage, 2),
      formatNum(r.current_ma, 2),
      formatSmartWatt(r.power_w),
      Math.round(Number(r.lux) || 0).toLocaleString("id-ID"),
      formatNum(r.temperature, 1)
    ].forEach((val) => {
      const td = document.createElement("td");
      td.textContent = val;
      tr.appendChild(td);
    });
    tbody.appendChild(tr);
  });
}

function formatExportTimestamp(isoStr) {
  const d = new Date(isoStr);
  if (Number.isNaN(d.getTime())) return String(isoStr || "--");
  const pad = (n) => String(n).padStart(2, "0");
  return `${d.getFullYear()}-${pad(d.getMonth() + 1)}-${pad(d.getDate())} ${pad(d.getHours())}:${pad(d.getMinutes())}:${pad(d.getSeconds())}`;
}

function getTelemetryStatusLabels(row) {
  const temp = Number(row.temperature) || 0;
  const lux = Number(row.lux) || 0;
  const warnTh = Number(appState.config.temp_threshold_warn) || 45;
  const alarmTh = Number(appState.config.temp_threshold_alarm) || 60;
  const lowLux = Number(appState.config.low_lux_threshold) || 500;

  const tempStatus = temp >= alarmTh ? "Overheat" : temp >= warnTh ? "Hangat" : "Normal";
  const lightStatus = lux < 50 ? "Gelap" : lux < lowLux ? "Redup" : "Cerah";
  return { tempStatus, lightStatus };
}

async function getExportRowsOrAlert() {
  let rows = appState.filteredHistory || [];
  if (rows.length === 0) {
    rows = await loadHistoryData();
  }
  if (!rows || rows.length === 0) {
    alert("Tidak ada data logging untuk diexport.");
    return null;
  }
  return rows;
}

function csvCell(val) {
  const str = String(val ?? "");
  return `"${str.replace(/"/g, '""')}"`;
}

async function exportHistoryCsv() {
  const rows = await getExportRowsOrAlert();
  if (!rows) return;

  const headers = [
    "No",
    "Waktu (WIB)",
    "Device ID",
    "Tegangan (V)",
    "Arus (mA)",
    "Daya (W)",
    "Daya (mW)",
    "Energi Akumulasi (Wh)",
    "Cahaya (Lux)",
    "Suhu Panel (C)",
    "Status Suhu",
    "Kondisi Cahaya"
  ];

  const lines = [
    headers.map(csvCell).join(","),
    ...rows.map((r, idx) => {
      const v = Number(r.voltage) || 0;
      const ma = Number(r.current_ma) || 0;
      const pw = Number(r.power_w) || 0;
      const wh = Number(r.energy_wh) || 0;
      const lux = Number(r.lux) || 0;
      const temp = Number(r.temperature) || 0;
      const { tempStatus, lightStatus } = getTelemetryStatusLabels(r);
      return [
        idx + 1,
        formatExportTimestamp(r.recorded_at),
        appState.settings.deviceId,
        v.toFixed(3),
        ma.toFixed(2),
        pw.toFixed(4),
        (pw * 1000).toFixed(2),
        wh.toFixed(4),
        Math.round(lux),
        temp.toFixed(2),
        tempStatus,
        lightStatus
      ].map(csvCell).join(",");
    })
  ];

  const bom = "\uFEFF";
  const blob = new Blob([bom + lines.join("\r\n")], { type: "text/csv;charset=utf-8;" });
  const url = URL.createObjectURL(blob);
  const a = document.createElement("a");
  a.href = url;
  a.download = `solarmon_${appState.settings.deviceId}_${new Date().toISOString().slice(0, 10)}.csv`;
  a.click();
  URL.revokeObjectURL(url);
}

async function exportHistoryExcel() {
  const rows = await getExportRowsOrAlert();
  if (!rows) return;

  if (typeof window.XLSX === "undefined") {
    alert("Library Excel belum siap. Menggunakan format CSV rapi sebagai cadangan.");
    await exportHistoryCsv();
    return;
  }

  const peakPower = Math.max(...rows.map((r) => Number(r.power_w) || 0));
  const avgLux = rows.reduce((s, r) => s + (Number(r.lux) || 0), 0) / rows.length;
  const avgTemp = rows.reduce((s, r) => s + (Number(r.temperature) || 0), 0) / rows.length;
  const firstE = Number(rows[0].energy_wh) || 0;
  const lastE = Number(rows[rows.length - 1].energy_wh) || 0;
  const deltaE = Math.max(0, lastE - firstE);
  const wx = appState.weather;

  const summaryRows = [
    ["LAPORAN MONITORING PANEL SURYA (SOLARMONITOR)"],
    ["Device ID", appState.settings.deviceId],
    ["Tanggal Cetak", formatExportTimestamp(new Date().toISOString())],
    ["Rentang Waktu", document.getElementById("historyRangeSelect")?.selectedOptions?.[0]?.text || "24 Jam Terakhir"],
    ["Jumlah Sampel", rows.length],
    ["Daya Puncak (W)", Number(peakPower.toFixed(4))],
    ["Energi Periode (Wh)", Number(deltaE.toFixed(4))],
    ["Rata-rata Cahaya (Lux)", Math.round(avgLux)],
    ["Rata-rata Suhu Panel (C)", Number(avgTemp.toFixed(2))],
    ["Lokasi Cuaca Outdoor", appState.settings.weatherLocationName || "Yogyakarta"],
    ["Cuaca Eksternal (Open-Meteo)", wx.loaded ? `${wx.conditionText}, Suhu Udara ${formatNum(wx.tempC, 1)} C, Awan ${Math.round(wx.cloudCover)}%, Radiasi ${Math.round(wx.radiationWm2)} W/m2` : "Belum dimuat"]
  ];

  const dataRows = rows.map((r, idx) => {
    const pw = Number(r.power_w) || 0;
    const { tempStatus, lightStatus } = getTelemetryStatusLabels(r);
    return {
      "No": idx + 1,
      "Waktu (WIB)": formatExportTimestamp(r.recorded_at),
      "Tegangan (V)": Number((Number(r.voltage) || 0).toFixed(3)),
      "Arus (mA)": Number((Number(r.current_ma) || 0).toFixed(2)),
      "Daya (W)": Number(pw.toFixed(4)),
      "Daya (mW)": Number((pw * 1000).toFixed(2)),
      "Energi (Wh)": Number((Number(r.energy_wh) || 0).toFixed(4)),
      "Cahaya (Lux)": Math.round(Number(r.lux) || 0),
      "Suhu Panel (C)": Number((Number(r.temperature) || 0).toFixed(2)),
      "Status Suhu": tempStatus,
      "Kondisi Cahaya": lightStatus
    };
  });

  const wb = window.XLSX.utils.book_new();
  const wsSummary = window.XLSX.utils.aoa_to_sheet(summaryRows);
  wsSummary["!cols"] = [{ wch: 28 }, { wch: 56 }];
  window.XLSX.utils.book_append_sheet(wb, wsSummary, "Ringkasan");

  const wsData = window.XLSX.utils.json_to_sheet(dataRows);
  wsData["!cols"] = [
    { wch: 6 },
    { wch: 21 },
    { wch: 14 },
    { wch: 12 },
    { wch: 12 },
    { wch: 12 },
    { wch: 13 },
    { wch: 14 },
    { wch: 16 },
    { wch: 13 },
    { wch: 15 }
  ];
  window.XLSX.utils.book_append_sheet(wb, wsData, "Log Telemetry");

  window.XLSX.writeFile(wb, `solarmon_${appState.settings.deviceId}_${new Date().toISOString().slice(0, 10)}.xlsx`);
}

async function exportHistoryPdf() {
  const rows = await getExportRowsOrAlert();
  if (!rows) return;

  const jsPDFCtor = window.jspdf?.jsPDF;
  if (!jsPDFCtor) {
    alert("Library PDF belum selesai dimuat. Pastikan koneksi internet aktif lalu coba lagi.");
    return;
  }

  const doc = new jsPDFCtor({ orientation: "portrait", unit: "mm", format: "a4" });
  const peakPower = Math.max(...rows.map((r) => Number(r.power_w) || 0));
  const avgLux = rows.reduce((s, r) => s + (Number(r.lux) || 0), 0) / rows.length;
  const avgTemp = rows.reduce((s, r) => s + (Number(r.temperature) || 0), 0) / rows.length;
  const firstE = Number(rows[0].energy_wh) || 0;
  const lastE = Number(rows[rows.length - 1].energy_wh) || 0;
  const deltaE = Math.max(0, lastE - firstE);
  const rangeText = document.getElementById("historyRangeSelect")?.selectedOptions?.[0]?.text || "24 Jam Terakhir";
  const wx = appState.weather;

  // Cobalt Blue Header Banner
  doc.setFillColor(30, 64, 175);
  doc.rect(0, 0, 210, 30, "F");
  doc.setTextColor(255, 255, 255);
  doc.setFont("helvetica", "bold");
  doc.setFontSize(16);
  doc.text("Laporan Monitoring Panel Surya - SolarMonitor", 14, 13);
  doc.setFont("helvetica", "normal");
  doc.setFontSize(9.5);
  doc.text(`Device: ${appState.settings.deviceId}  |  Periode: ${rangeText}  |  Dicetak: ${formatExportTimestamp(new Date().toISOString())}`, 14, 21);

  // Summary Metrics Box
  doc.setTextColor(15, 23, 42);
  doc.setFillColor(239, 244, 255);
  doc.roundedRect(14, 36, 182, 24, 3, 3, "F");

  doc.setFont("helvetica", "bold");
  doc.setFontSize(9.5);
  doc.text(`Daya Puncak: ${formatSmartWatt(peakPower)} W`, 18, 44);
  doc.text(`Energi Periode: ${formatSmartWatt(deltaE)} Wh`, 78, 44);
  doc.text(`Total Sampel: ${rows.length} log`, 145, 44);

  doc.setFont("helvetica", "normal");
  doc.text(`Rata-rata Cahaya: ${Math.round(avgLux).toLocaleString("id-ID")} Lux`, 18, 51);
  doc.text(`Rata-rata Suhu Panel: ${avgTemp.toFixed(1)} C`, 78, 51);
  const wxSummary = wx.loaded
    ? `Cuaca (${appState.settings.weatherLocationName}): ${wx.conditionText}, ${formatNum(wx.tempC, 1)} C, Awan ${Math.round(wx.cloudCover)}%, Radiasi ${Math.round(wx.radiationWm2)} W/m2`
    : `Lokasi: ${appState.settings.weatherLocationName || "Yogyakarta"} (Cuaca eksternal belum dimuat)`;
  doc.text(wxSummary, 18, 57);

  // Table (max 250 latest rows so PDF remains crisp and fast)
  const pdfRows = rows.slice(-250).reverse().map((r, idx) => {
    const pw = Number(r.power_w) || 0;
    const { tempStatus } = getTelemetryStatusLabels(r);
    return [
      idx + 1,
      formatExportTimestamp(r.recorded_at),
      formatNum(r.voltage, 2),
      formatNum(r.current_ma, 2),
      formatSmartWatt(pw),
      Math.round(Number(r.lux) || 0).toLocaleString("id-ID"),
      formatNum(r.temperature, 1),
      tempStatus
    ];
  });

  if (typeof doc.autoTable === "function") {
    doc.autoTable({
      startY: 66,
      head: [["No", "Waktu (WIB)", "Teg (V)", "Arus (mA)", "Daya (W)", "Cahaya (Lux)", "Suhu (C)", "Status"]],
      body: pdfRows,
      styles: { fontSize: 8, cellPadding: 2.2, textColor: [15, 23, 42] },
      headStyles: { fillColor: [30, 64, 175], textColor: [255, 255, 255], fontStyle: "bold" },
      alternateRowStyles: { fillColor: [248, 250, 252] },
      margin: { left: 14, right: 14 }
    });
  }

  doc.save(`Laporan_SolarMonitor_${appState.settings.deviceId}_${new Date().toISOString().slice(0, 10)}.pdf`);
}

// ----------------------------------------------------------------------------
// HALAMAN 5: SETTINGS, AUTH & EVENTS
// ----------------------------------------------------------------------------
function syncSettingsForm() {
  const s = appState.settings;
  document.getElementById("cfgBackendMode").value = s.mode;
  document.getElementById("cfgSupabaseUrl").value = s.supabaseUrl;
  document.getElementById("cfgSupabaseKey").value = s.supabaseAnonKey;
  document.getElementById("cfgDeviceId").value = s.deviceId;
  const wxNameEl = document.getElementById("cfgWeatherName");
  const wxLatEl = document.getElementById("cfgWeatherLat");
  const wxLonEl = document.getElementById("cfgWeatherLon");
  if (wxNameEl) wxNameEl.value = s.weatherLocationName || "Yogyakarta";
  if (wxLatEl) wxLatEl.value = s.weatherLat ?? -7.7956;
  if (wxLonEl) wxLonEl.value = s.weatherLon ?? 110.3695;

  toggleBackendFields(s.mode);
  syncThresholdInputs();
  updateAuthUI();
}

function syncThresholdInputs() {
  const c = appState.config;
  document.getElementById("cfgCapacityWp").value = c.panel_capacity_wp;
  document.getElementById("cfgThWarn").value = c.temp_threshold_warn;
  document.getElementById("cfgThAlarm").value = c.temp_threshold_alarm;
  document.getElementById("cfgLowLux").value = c.low_lux_threshold;
  document.getElementById("cfgLogInterval").value = c.log_interval_seconds;
}

function toggleBackendFields(mode) {
  document.getElementById("supabaseFields")?.classList.toggle("hidden", mode !== "supabase");
  document.getElementById("simulatorLabBox")?.classList.toggle("hidden", mode !== "simulator");
}

function updateAuthUI() {
  const isSupa = appState.settings.mode === "supabase";
  const loggedIn = Boolean(appState.user);
  document.getElementById("btnOpenLoginModal")?.classList.toggle("hidden", !isSupa || loggedIn);
  document.getElementById("btnLogoutSupabase")?.classList.toggle("hidden", !isSupa || !loggedIn);
  document.getElementById("btnResetLogs")?.classList.toggle("hidden", !isSupa || !canAdminEdit());

  if (!isSupa) {
    setText("settingsAuthSummary", "Mode Simulasi aktif. Anda bisa menguji seluruh fitur UI tanpa database.");
  } else if (loggedIn) {
    setText("settingsAuthSummary", `Login sebagai ${appState.user.email} (Role: ${appState.role.toUpperCase()})`);
  } else {
    setText("settingsAuthSummary", "Akses publik aktif (Read-Only). Login sebagai Admin untuk mengubah konfigurasi cloud.");
  }
}

function openLoginModal() {
  document.getElementById("loginErrorMsg")?.classList.add("hidden");
  document.getElementById("loginModal")?.classList.remove("hidden");
}

function closeLoginModal() {
  document.getElementById("loginModal")?.classList.add("hidden");
}

function startActiveBackend() {
  const mode = appState.settings.mode;
  syncSettingsForm();
  if (mode === "supabase") startSupabaseMode();
  else startSimulatorMode();
}

function triggerManualSync() {
  if (appState.settings.mode === "supabase") {
    fetchSupabaseInitialState(true);
  } else {
    evaluateSimulatorPhysics(2);
    pushRealtimePoint(appState.telemetry);
    renderAll();
  }
}

function bindEvents() {
  document.querySelectorAll(".nav-item, .mob-nav-item").forEach((btn) => {
    btn.addEventListener("click", () => navigateTo(btn.dataset.page));
  });
  document.querySelectorAll("[data-goto]").forEach((btn) => {
    btn.addEventListener("click", () => navigateTo(btn.dataset.goto));
  });

  document.getElementById("menuToggleBtn")?.addEventListener("click", () => {
    document.getElementById("sidebar")?.classList.toggle("open");
    document.getElementById("sidebarBackdrop")?.classList.toggle("show");
  });
  document.getElementById("sidebarBackdrop")?.addEventListener("click", () => {
    document.getElementById("sidebar")?.classList.remove("open");
    document.getElementById("sidebarBackdrop")?.classList.remove("show");
  });

  // Search bar filter & Ctrl+K shortcut
  const searchInput = document.getElementById("globalSearchInput");
  searchInput?.addEventListener("input", (e) => {
    appState.searchQuery = e.target.value;
    renderOverviewRecentLogs();
  });
  document.addEventListener("keydown", (e) => {
    if ((e.ctrlKey || e.metaKey) && e.key.toLowerCase() === "k") {
      e.preventDefault();
      searchInput?.focus();
    }
  });

  // Sync & Export shortcuts
  ["headerSyncBtn", "btnDashSyncNow", "btnTrackerRefresh"].forEach((id) => {
    document.getElementById(id)?.addEventListener("click", triggerManualSync);
  });
  document.getElementById("btnDashExportCsv")?.addEventListener("click", exportHistoryCsv);
  document.getElementById("btnDashExportPdf")?.addEventListener("click", exportHistoryPdf);
  document.getElementById("btnExportCsv")?.addEventListener("click", exportHistoryCsv);
  document.getElementById("btnExportExcel")?.addEventListener("click", exportHistoryExcel);
  document.getElementById("btnExportPdf")?.addEventListener("click", exportHistoryPdf);
  document.getElementById("btnRefreshWeather")?.addEventListener("click", fetchWeatherData);

  document.getElementById("weatherLocationForm")?.addEventListener("submit", (e) => {
    e.preventDefault();
    const lat = Number(document.getElementById("cfgWeatherLat")?.value);
    const lon = Number(document.getElementById("cfgWeatherLon")?.value);
    const name = document.getElementById("cfgWeatherName")?.value.trim() || "Lokasi Panel";
    if (!Number.isFinite(lat) || !Number.isFinite(lon)) {
      alert("Masukkan nilai Latitude dan Longitude yang valid.");
      return;
    }
    saveSettingsToStorage({
      weatherLocationName: name,
      weatherLat: lat,
      weatherLon: lon
    });
    fetchWeatherData();
    alert("Lokasi cuaca outdoor berhasil diperbarui.");
  });

  document.getElementById("btnDetectGps")?.addEventListener("click", () => {
    if (!navigator.geolocation) {
      alert("Browser Anda tidak mendukung GPS Geolocation.");
      return;
    }
    navigator.geolocation.getCurrentPosition(
      (pos) => {
        const lat = Number(pos.coords.latitude.toFixed(4));
        const lon = Number(pos.coords.longitude.toFixed(4));
        document.getElementById("cfgWeatherLat").value = lat;
        document.getElementById("cfgWeatherLon").value = lon;
        document.getElementById("cfgWeatherName").value = "Lokasi GPS Saat Ini";
        saveSettingsToStorage({
          weatherLocationName: "Lokasi GPS Saat Ini",
          weatherLat: lat,
          weatherLon: lon
        });
        fetchWeatherData();
      },
      (err) => {
        alert("Gagal mendeteksi lokasi GPS: " + (err?.message || "Izin ditolak"));
      },
      { enableHighAccuracy: false, timeout: 8000 }
    );
  });

  // Pause / Resume auto-sync (Uptime Tracker button)
  document.getElementById("btnToggleSync")?.addEventListener("click", () => {
    appState.autoSyncEnabled = !appState.autoSyncEnabled;
    document.getElementById("syncPauseIcon")?.classList.toggle("hidden", !appState.autoSyncEnabled);
    document.getElementById("syncPlayIcon")?.classList.toggle("hidden", appState.autoSyncEnabled);
    renderAll();
  });

  // History filter
  document.getElementById("historyRangeSelect")?.addEventListener("change", (e) => {
    document.getElementById("customDateWrap")?.classList.toggle("hidden", e.target.value !== "custom");
  });
  document.getElementById("btnLoadHistory")?.addEventListener("click", loadHistoryData);

  // Settings Backend
  document.getElementById("cfgBackendMode")?.addEventListener("change", (e) => {
    toggleBackendFields(e.target.value);
  });

  document.getElementById("backendConfigForm")?.addEventListener("submit", (e) => {
    e.preventDefault();
    saveSettingsToStorage({
      mode: document.getElementById("cfgBackendMode").value,
      supabaseUrl: document.getElementById("cfgSupabaseUrl").value.trim().replace(/\/$/, ""),
      supabaseAnonKey: document.getElementById("cfgSupabaseKey").value.trim(),
      deviceId: document.getElementById("cfgDeviceId").value.trim() || "ESP32_SOLAR_01"
    });
    appState.realtimeSeries = [];
    appState.recentLogs = [];
    appState.historyLogs = [];
    appState.filteredHistory = [];
    appState.activityLogs = [];
    startActiveBackend();
    alert("Konfigurasi sumber data berhasil diterapkan.");
  });

  document.getElementById("thresholdForm")?.addEventListener("submit", async (e) => {
    e.preventDefault();
    if (!canAdminEdit()) {
      alert("Silakan login sebagai Admin Supabase terlebih dahulu untuk menyimpan konfigurasi ke cloud.");
      openLoginModal();
      return;
    }

    const patch = {
      panel_capacity_wp: Number(document.getElementById("cfgCapacityWp").value),
      temp_threshold_warn: Number(document.getElementById("cfgThWarn").value),
      temp_threshold_alarm: Number(document.getElementById("cfgThAlarm").value),
      low_lux_threshold: Number(document.getElementById("cfgLowLux").value),
      log_interval_seconds: Number(document.getElementById("cfgLogInterval").value)
    };

    if (patch.temp_threshold_warn >= patch.temp_threshold_alarm) {
      alert("Ambang suhu hangat harus lebih rendah daripada ambang overheat alarm.");
      return;
    }

    appState.config = { ...appState.config, ...patch };
    renderAll();

    if (appState.settings.mode === "supabase" && supabaseClient) {
      const { error } = await supabaseClient
        .from("solar_config")
        .update(patch)
        .eq("device_id", appState.settings.deviceId);

      if (error) {
        alert("Gagal menyimpan ke Supabase: " + error.message);
        await fetchSupabaseInitialState(false);
        return;
      }
    }
    alert("Konfigurasi panel berhasil disimpan.");
  });

  document.getElementById("btnSimDark")?.addEventListener("click", () => { appState.simPreset = "dark"; });
  document.getElementById("btnSimNormal")?.addEventListener("click", () => { appState.simPreset = "normal"; });
  document.getElementById("btnSimPeak")?.addEventListener("click", () => { appState.simPreset = "peak"; });

  document.getElementById("authActionBtn")?.addEventListener("click", () => {
    if (appState.settings.mode === "supabase" && !appState.user) openLoginModal();
    else navigateTo("settings");
  });
  document.getElementById("btnOpenLoginModal")?.addEventListener("click", openLoginModal);
  document.getElementById("closeLoginModalBtn")?.addEventListener("click", closeLoginModal);

  document.getElementById("supabaseLoginForm")?.addEventListener("submit", async (e) => {
    e.preventDefault();
    const errEl = document.getElementById("loginErrorMsg");
    const submitBtn = e.target.querySelector('button[type="submit"]');
    if (!supabaseClient) {
      if (errEl) {
        errEl.textContent = "Koneksi Supabase belum aktif. Simpan URL & Key di Settings terlebih dahulu.";
        errEl.classList.remove("hidden");
      }
      return;
    }

    const email = document.getElementById("loginEmail").value.trim();
    const password = document.getElementById("loginPassword").value;

    if (submitBtn) submitBtn.disabled = true;
    try {
      const { error } = await supabaseClient.auth.signInWithPassword({ email, password });
      if (error) {
        if (errEl) {
          errEl.textContent = "Login gagal: " + error.message;
          errEl.classList.remove("hidden");
        }
      } else {
        closeLoginModal();
      }
    } finally {
      if (submitBtn) submitBtn.disabled = false;
    }
  });

  document.getElementById("btnLogoutSupabase")?.addEventListener("click", async () => {
    if (supabaseClient) await supabaseClient.auth.signOut();
    appState.user = null;
    appState.role = "viewer";
    renderAll();
    updateAuthUI();
  });

  document.getElementById("btnResetLogs")?.addEventListener("click", async () => {
    if (!supabaseClient || !canAdminEdit()) return;
    if (!confirm("Hapus seluruh data logging uji dan reset akumulator energi ke 0 Wh?")) return;
    const { data, error } = await supabaseClient.rpc("reset_solar_logs", { p_device_id: appState.settings.deviceId });
    if (error) {
      alert("Gagal mereset data: " + error.message);
    } else {
      alert(`Berhasil membersihkan ${data?.deleted_rows ?? 0} baris log uji.`);
      await fetchSupabaseInitialState(true);
    }
  });
}

// ----------------------------------------------------------------------------
// PWA INSTALL PROMPT & SERVICE WORKER
// ----------------------------------------------------------------------------
let deferredInstallPrompt = null;

function isRunningStandalone() {
  return (
    window.matchMedia?.("(display-mode: standalone)")?.matches ||
    window.navigator.standalone === true
  );
}

function initPwaSupport() {
  if ("serviceWorker" in navigator && window.location.protocol.startsWith("http")) {
    navigator.serviceWorker.register("./sw.js").catch(() => {});
  }

  const installBtns = [
    document.getElementById("installAppBtn"),
    document.getElementById("sidebarInstallBtn")
  ].filter(Boolean);
  const guideModal = document.getElementById("installGuideModal");

  window.addEventListener("beforeinstallprompt", (e) => {
    e.preventDefault();
    deferredInstallPrompt = e;
  });

  const handleInstallClick = async () => {
    if (deferredInstallPrompt) {
      deferredInstallPrompt.prompt();
      const choice = await deferredInstallPrompt.userChoice;
      if (choice?.outcome === "accepted") {
        deferredInstallPrompt = null;
      }
      return;
    }
    guideModal?.classList.remove("hidden");
  };

  installBtns.forEach((btn) => btn.addEventListener("click", handleInstallClick));

  document.getElementById("closeInstallGuideBtn")?.addEventListener("click", () => {
    guideModal?.classList.add("hidden");
  });

  document.getElementById("btnOpenInChrome")?.addEventListener("click", () => {
    const cleanUrl = window.location.href.replace(/^https?:\/\//, "");
    const isAndroid = /Android/i.test(navigator.userAgent);
    if (isAndroid) {
      window.location.href = `intent://${cleanUrl}#Intent;scheme=https;package=com.android.chrome;end`;
    } else {
      navigator.clipboard?.writeText(window.location.href);
      alert("Link disalin! Buka Google Chrome atau Safari lalu tempelkan link tersebut.");
    }
  });

  document.getElementById("btnCopyAppLink")?.addEventListener("click", async () => {
    try {
      await navigator.clipboard.writeText(window.location.href);
      alert("Link aplikasi berhasil disalin.");
    } catch {
      prompt("Salin link aplikasi berikut:", window.location.href);
    }
  });

  window.addEventListener("appinstalled", () => {
    deferredInstallPrompt = null;
    guideModal?.classList.add("hidden");
  });
}

// ----------------------------------------------------------------------------
// INISIALISASI SAAT HALAMAN SIAP
// ----------------------------------------------------------------------------
document.addEventListener("DOMContentLoaded", () => {
  initCharts();
  bindEvents();
  initPwaSupport();
  startActiveBackend();
  fetchWeatherData();
  setInterval(fetchWeatherData, 10 * 60 * 1000);

  heartbeatTimerId = setInterval(() => {
    const currentlyOnline = isDeviceOnline();
    if (currentlyOnline !== appState.lastOnlineState) {
      appState.lastOnlineState = currentlyOnline;
      renderAll();
    } else {
      updateHeartbeatBadge();
      evaluateAlerts();
    }
  }, 1000);
});
