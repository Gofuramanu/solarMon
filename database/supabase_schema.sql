-- ============================================================================
-- SKEMA DATABASE SUPABASE (POSTGRESQL) - SOLARMONITOR ESP32 IOT
-- Sistem Monitoring Parameter Kelistrikan, Intensitas Cahaya & Suhu Panel Surya
-- Hardware: ESP32 + INA219 + BH1750 + DS18B20 + LCD 16x2 I2C
-- ============================================================================
-- Jalankan seluruh script ini di Supabase SQL Editor.
-- Script ini idempoten (aman dijalankan ulang tanpa merusak tabel yang ada).
-- ============================================================================

CREATE EXTENSION IF NOT EXISTS pgcrypto;

-- ----------------------------------------------------------------------------
-- 1. TABEL ROLE PENGGUNA (RBAC: admin, operator, viewer)
-- ----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS public.user_roles (
  user_id UUID PRIMARY KEY REFERENCES auth.users(id) ON DELETE CASCADE,
  email TEXT,
  role TEXT NOT NULL DEFAULT 'viewer' CHECK (role IN ('admin', 'operator', 'viewer')),
  created_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
  updated_at TIMESTAMPTZ NOT NULL DEFAULT NOW()
);

CREATE OR REPLACE FUNCTION public.get_my_role()
RETURNS TEXT
LANGUAGE sql
STABLE
SECURITY DEFINER
SET search_path = public
AS $$
  SELECT COALESCE(
    (SELECT role FROM public.user_roles WHERE user_id = auth.uid()),
    'viewer'
  );
$$;

CREATE OR REPLACE FUNCTION public.handle_new_user()
RETURNS TRIGGER
LANGUAGE plpgsql
SECURITY DEFINER
SET search_path = public
AS $$
BEGIN
  INSERT INTO public.user_roles (user_id, email, role)
  VALUES (NEW.id, NEW.email, 'viewer')
  ON CONFLICT (user_id) DO NOTHING;
  RETURN NEW;
END;
$$;

DROP TRIGGER IF EXISTS on_auth_user_created ON auth.users;
CREATE TRIGGER on_auth_user_created
  AFTER INSERT ON auth.users
  FOR EACH ROW EXECUTE FUNCTION public.handle_new_user();

-- ----------------------------------------------------------------------------
-- 2. TABEL PERANGKAT TERDAFTAR (NODE ESP32)
-- ----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS public.devices (
  device_id TEXT PRIMARY KEY,
  name TEXT NOT NULL,
  location TEXT,
  panel_spec TEXT DEFAULT 'Panel Surya DC (INA219 + BH1750 + DS18B20)',
  device_secret_hash TEXT NULL,
  is_active BOOLEAN NOT NULL DEFAULT TRUE,
  created_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
  updated_at TIMESTAMPTZ NOT NULL DEFAULT NOW()
);

ALTER TABLE public.devices ALTER COLUMN device_secret_hash DROP NOT NULL;

-- ----------------------------------------------------------------------------
-- 3. TABEL LIVE STATE PANEL SURYA (1 Baris per Perangkat - Realtime)
-- ----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS public.solar_state (
  device_id TEXT PRIMARY KEY REFERENCES public.devices(device_id) ON DELETE CASCADE,
  voltage DOUBLE PRECISION NOT NULL DEFAULT 0,
  current_ma DOUBLE PRECISION NOT NULL DEFAULT 0,
  power_w DOUBLE PRECISION NOT NULL DEFAULT 0,
  energy_wh DOUBLE PRECISION NOT NULL DEFAULT 0,
  lux DOUBLE PRECISION NOT NULL DEFAULT 0,
  temperature DOUBLE PRECISION NOT NULL DEFAULT 0,
  ina_ok BOOLEAN NOT NULL DEFAULT FALSE,
  bh_ok BOOLEAN NOT NULL DEFAULT FALSE,
  temp_ok BOOLEAN NOT NULL DEFAULT FALSE,
  uptime_seconds BIGINT NOT NULL DEFAULT 0,
  wifi_connected BOOLEAN NOT NULL DEFAULT FALSE,
  wifi_rssi INTEGER,
  last_seen_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
  updated_at TIMESTAMPTZ NOT NULL DEFAULT NOW()
);

-- ----------------------------------------------------------------------------
-- 4. TABEL KONFIGURASI & AMBANG BATAS PANEL (Update dari Settings Web)
-- ----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS public.solar_config (
  device_id TEXT PRIMARY KEY REFERENCES public.devices(device_id) ON DELETE CASCADE,
  panel_capacity_wp DOUBLE PRECISION NOT NULL DEFAULT 10.0 CHECK (panel_capacity_wp BETWEEN 1 AND 50000),
  temp_threshold_warn DOUBLE PRECISION NOT NULL DEFAULT 45.0 CHECK (temp_threshold_warn BETWEEN 20 AND 90),
  temp_threshold_alarm DOUBLE PRECISION NOT NULL DEFAULT 60.0 CHECK (temp_threshold_alarm BETWEEN 25 AND 105),
  low_lux_threshold DOUBLE PRECISION NOT NULL DEFAULT 500.0 CHECK (low_lux_threshold BETWEEN 0 AND 100000),
  offline_timeout_seconds INTEGER NOT NULL DEFAULT 30 CHECK (offline_timeout_seconds BETWEEN 10 AND 600),
  log_interval_seconds INTEGER NOT NULL DEFAULT 10 CHECK (log_interval_seconds BETWEEN 5 AND 3600),
  updated_by UUID REFERENCES auth.users(id) ON DELETE SET NULL,
  updated_at TIMESTAMPTZ NOT NULL DEFAULT NOW()
);

-- ----------------------------------------------------------------------------
-- 5. TABEL DATA LOGGING TELEMETRI (Time-Series Logs)
-- ----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS public.telemetry_logs (
  id BIGSERIAL PRIMARY KEY,
  device_id TEXT NOT NULL REFERENCES public.devices(device_id) ON DELETE CASCADE,
  voltage DOUBLE PRECISION NOT NULL DEFAULT 0,
  current_ma DOUBLE PRECISION NOT NULL DEFAULT 0,
  power_w DOUBLE PRECISION NOT NULL DEFAULT 0,
  energy_wh DOUBLE PRECISION NOT NULL DEFAULT 0,
  lux DOUBLE PRECISION NOT NULL DEFAULT 0,
  temperature DOUBLE PRECISION NOT NULL DEFAULT 0,
  ina_ok BOOLEAN NOT NULL DEFAULT TRUE,
  bh_ok BOOLEAN NOT NULL DEFAULT TRUE,
  temp_ok BOOLEAN NOT NULL DEFAULT TRUE,
  uptime_seconds BIGINT NULL DEFAULT 0,
  wifi_connected BOOLEAN NOT NULL DEFAULT TRUE,
  recorded_at TIMESTAMPTZ NOT NULL DEFAULT NOW()
);

CREATE INDEX IF NOT EXISTS idx_solar_telemetry_device_time
  ON public.telemetry_logs (device_id, recorded_at DESC);

-- ----------------------------------------------------------------------------
-- 6. TABEL AUDIT LOG AKTIVITAS (Sensor Status, Overheat, Config)
-- ----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS public.activity_logs (
  id BIGSERIAL PRIMARY KEY,
  device_id TEXT NOT NULL REFERENCES public.devices(device_id) ON DELETE CASCADE,
  actor_type TEXT NOT NULL CHECK (actor_type IN ('ESP32', 'USER', 'SYSTEM')),
  actor_name TEXT NOT NULL,
  event_type TEXT NOT NULL,
  description TEXT NOT NULL,
  created_at TIMESTAMPTZ NOT NULL DEFAULT NOW()
);

CREATE INDEX IF NOT EXISTS idx_solar_activity_device_time
  ON public.activity_logs (device_id, created_at DESC);

-- ----------------------------------------------------------------------------
-- 7. TRIGGER AUDIT PERUBAHAN KONFIGURASI DARI DASHBOARD
-- ----------------------------------------------------------------------------
CREATE OR REPLACE FUNCTION public.log_solar_config_changes()
RETURNS TRIGGER
LANGUAGE plpgsql
SECURITY DEFINER
SET search_path = public
AS $$
DECLARE
  v_actor_email TEXT;
  v_changes TEXT := '';
BEGIN
  NEW.updated_at := NOW();
  IF auth.uid() IS NOT NULL THEN
    NEW.updated_by := auth.uid();
  END IF;

  SELECT COALESCE(email, 'Admin') INTO v_actor_email
  FROM auth.users WHERE id = NEW.updated_by;

  IF OLD.panel_capacity_wp IS DISTINCT FROM NEW.panel_capacity_wp THEN
    v_changes := v_changes || format('Kapasitas: %s Wp; ', NEW.panel_capacity_wp);
  END IF;
  IF OLD.temp_threshold_warn IS DISTINCT FROM NEW.temp_threshold_warn
     OR OLD.temp_threshold_alarm IS DISTINCT FROM NEW.temp_threshold_alarm THEN
    v_changes := v_changes || format(
      'Suhu [Warn:%sC, Alarm:%sC]; ',
      NEW.temp_threshold_warn, NEW.temp_threshold_alarm
    );
  END IF;
  IF OLD.log_interval_seconds IS DISTINCT FROM NEW.log_interval_seconds THEN
    v_changes := v_changes || format('Interval Log: %sd; ', NEW.log_interval_seconds);
  END IF;

  IF v_changes <> '' THEN
    INSERT INTO public.activity_logs (device_id, actor_type, actor_name, event_type, description)
    VALUES (NEW.device_id, 'USER', COALESCE(v_actor_email, 'Dashboard Admin'), 'CONFIG_UPDATED', btrim(v_changes));
  END IF;

  RETURN NEW;
END;
$$;

DROP TRIGGER IF EXISTS trg_solar_config_audit ON public.solar_config;
CREATE TRIGGER trg_solar_config_audit
  BEFORE UPDATE ON public.solar_config
  FOR EACH ROW EXECUTE FUNCTION public.log_solar_config_changes();

-- ----------------------------------------------------------------------------
-- 8. RPC ENDPOINT TUNGGAL UNTUK ESP32 (Kirim Sensor + Integrasi Energi Wh)
-- ----------------------------------------------------------------------------
DROP FUNCTION IF EXISTS public.sync_solar_telemetry(
  TEXT, TEXT, DOUBLE PRECISION, DOUBLE PRECISION, DOUBLE PRECISION,
  DOUBLE PRECISION, DOUBLE PRECISION, BOOLEAN, BOOLEAN, BOOLEAN,
  BIGINT, BOOLEAN, INTEGER
);

CREATE OR REPLACE FUNCTION public.sync_solar_telemetry(
  p_device_id TEXT,
  p_device_key TEXT DEFAULT NULL,
  p_voltage DOUBLE PRECISION DEFAULT 0,
  p_current_ma DOUBLE PRECISION DEFAULT 0,
  p_power_w DOUBLE PRECISION DEFAULT 0,
  p_lux DOUBLE PRECISION DEFAULT 0,
  p_temperature DOUBLE PRECISION DEFAULT 0,
  p_ina_ok BOOLEAN DEFAULT TRUE,
  p_bh_ok BOOLEAN DEFAULT TRUE,
  p_temp_ok BOOLEAN DEFAULT TRUE,
  p_uptime_seconds BIGINT DEFAULT 0,
  p_wifi_connected BOOLEAN DEFAULT TRUE,
  p_wifi_rssi INTEGER DEFAULT NULL
)
RETURNS JSONB
LANGUAGE plpgsql
SECURITY DEFINER
SET search_path = public, extensions
AS $$
DECLARE
  v_secret_hash TEXT;
  v_is_active BOOLEAN;
  v_prev_state public.solar_state%ROWTYPE;
  v_config public.solar_config%ROWTYPE;
  v_last_log_time TIMESTAMPTZ;
  v_elapsed_sec DOUBLE PRECISION := 0;
  v_energy_wh DOUBLE PRECISION := 0;
  v_safe_voltage DOUBLE PRECISION;
  v_safe_current DOUBLE PRECISION;
  v_safe_power DOUBLE PRECISION;
  v_safe_lux DOUBLE PRECISION;
  v_safe_temp DOUBLE PRECISION;
  v_is_alarm BOOLEAN := FALSE;
  v_prev_alarm BOOLEAN := FALSE;
  v_sensor_changed BOOLEAN := FALSE;
BEGIN
  -- 1. Cek perangkat & auto-register jika device_id baru
  SELECT device_secret_hash, is_active
  INTO v_secret_hash, v_is_active
  FROM public.devices
  WHERE device_id = p_device_id;

  IF NOT FOUND THEN
    INSERT INTO public.devices (device_id, name, device_secret_hash, is_active)
    VALUES (p_device_id, p_device_id, NULL, TRUE)
    ON CONFLICT (device_id) DO NOTHING;

    v_secret_hash := NULL;
    v_is_active := TRUE;
  END IF;

  IF NOT v_is_active THEN
    RAISE EXCEPTION 'Device nonaktif' USING ERRCODE = '28000';
  END IF;

  -- Verifikasi device_key opsional (aktif jika device_secret_hash diisi oleh admin)
  IF v_secret_hash IS NOT NULL AND v_secret_hash <> '' THEN
    IF p_device_key IS NULL OR encode(digest(p_device_key, 'sha256'), 'hex') <> v_secret_hash THEN
      RAISE EXCEPTION 'Autentikasi device_key tidak valid' USING ERRCODE = '28000';
    END IF;
  END IF;

  -- 2. Sanitasi & validasi batas fisika sensor panel surya (tolak -127C / noise ekstrem)
  v_safe_voltage := GREATEST(COALESCE(p_voltage, 0), 0);
  v_safe_current := GREATEST(COALESCE(p_current_ma, 0), 0);
  v_safe_power := GREATEST(COALESCE(p_power_w, 0), 0);
  v_safe_lux := GREATEST(COALESCE(p_lux, 0), 0);
  v_safe_temp := COALESCE(p_temperature, 0);

  IF v_safe_voltage > 60 OR v_safe_current > 10000 OR v_safe_power > 600 THEN
    RAISE EXCEPTION 'Nilai kelistrikan INA219 di luar rentang wajar' USING ERRCODE = '22023';
  END IF;

  IF v_safe_lux > 150000 THEN
    RAISE EXCEPTION 'Nilai intensitas cahaya BH1750 di luar rentang wajar' USING ERRCODE = '22023';
  END IF;

  IF p_temp_ok AND (v_safe_temp <= -120 OR v_safe_temp < -40 OR v_safe_temp > 125) THEN
    RAISE EXCEPTION 'Nilai temperatur DS18B20 tidak valid' USING ERRCODE = '22023';
  END IF;

  -- 3. Ambil state sebelumnya & konfigurasi aktif
  SELECT * INTO v_prev_state FROM public.solar_state WHERE device_id = p_device_id;
  SELECT * INTO v_config FROM public.solar_config WHERE device_id = p_device_id;

  IF v_config.device_id IS NULL THEN
    INSERT INTO public.solar_config (device_id) VALUES (p_device_id)
    RETURNING * INTO v_config;
  END IF;

  -- 4. Hitung akumulasi energi (Wh) menggunakan metode Trapezoidal Rule (skip gap > 300 detik)
  IF v_prev_state.device_id IS NOT NULL THEN
    v_energy_wh := COALESCE(v_prev_state.energy_wh, 0);
    v_elapsed_sec := EXTRACT(EPOCH FROM (NOW() - v_prev_state.last_seen_at));
    IF v_elapsed_sec > 0 AND v_elapsed_sec <= 300 THEN
      v_energy_wh := v_energy_wh + (((COALESCE(v_prev_state.power_w, 0) + v_safe_power) / 2.0) * (v_elapsed_sec / 3600.0));
    END IF;

    v_prev_alarm := v_prev_state.temp_ok AND (v_prev_state.temperature >= v_config.temp_threshold_alarm);
    v_sensor_changed := (
      v_prev_state.ina_ok IS DISTINCT FROM p_ina_ok OR
      v_prev_state.bh_ok IS DISTINCT FROM p_bh_ok OR
      v_prev_state.temp_ok IS DISTINCT FROM p_temp_ok
    );
  END IF;

  v_is_alarm := p_temp_ok AND (v_safe_temp >= v_config.temp_threshold_alarm);

  -- 5. Upsert live state di solar_state
  INSERT INTO public.solar_state (
    device_id, voltage, current_ma, power_w, energy_wh, lux, temperature,
    ina_ok, bh_ok, temp_ok,
    uptime_seconds, wifi_connected, wifi_rssi, last_seen_at, updated_at
  )
  VALUES (
    p_device_id, v_safe_voltage, v_safe_current, v_safe_power, v_energy_wh, v_safe_lux, v_safe_temp,
    p_ina_ok, p_bh_ok, p_temp_ok,
    GREATEST(p_uptime_seconds, 0), p_wifi_connected, p_wifi_rssi, NOW(), NOW()
  )
  ON CONFLICT (device_id) DO UPDATE SET
    voltage = EXCLUDED.voltage,
    current_ma = EXCLUDED.current_ma,
    power_w = EXCLUDED.power_w,
    energy_wh = EXCLUDED.energy_wh,
    lux = EXCLUDED.lux,
    temperature = EXCLUDED.temperature,
    ina_ok = EXCLUDED.ina_ok,
    bh_ok = EXCLUDED.bh_ok,
    temp_ok = EXCLUDED.temp_ok,
    uptime_seconds = EXCLUDED.uptime_seconds,
    wifi_connected = EXCLUDED.wifi_connected,
    wifi_rssi = EXCLUDED.wifi_rssi,
    last_seen_at = NOW(),
    updated_at = NOW();

  -- 6. Simpan ke telemetry_logs sesuai interval logging atau saat terjadi alert/perubahan sensor
  SELECT MAX(recorded_at) INTO v_last_log_time
  FROM public.telemetry_logs
  WHERE device_id = p_device_id;

  IF v_last_log_time IS NULL
     OR NOW() - v_last_log_time >= make_interval(secs => v_config.log_interval_seconds)
     OR (v_is_alarm AND NOT v_prev_alarm)
     OR v_sensor_changed THEN
    INSERT INTO public.telemetry_logs (
      device_id, voltage, current_ma, power_w, energy_wh, lux, temperature,
      ina_ok, bh_ok, temp_ok, uptime_seconds, wifi_connected, recorded_at
    )
    VALUES (
      p_device_id, v_safe_voltage, v_safe_current, v_safe_power, v_energy_wh, v_safe_lux, v_safe_temp,
      p_ina_ok, p_bh_ok, p_temp_ok, GREATEST(p_uptime_seconds, 0), p_wifi_connected, NOW()
    );
  END IF;

  -- 7. Audit Log saat terjadi sensor error, pemulihan, atau overheat panel
  IF v_sensor_changed THEN
    INSERT INTO public.activity_logs (device_id, actor_type, actor_name, event_type, description)
    VALUES (
      p_device_id,
      'ESP32',
      p_device_id,
      CASE WHEN (p_ina_ok AND p_bh_ok AND p_temp_ok) THEN 'SENSOR_READY' ELSE 'SENSOR_FAULT' END,
      format(
        'Status Sensor -> INA219:%s, BH1750:%s, DS18B20:%s',
        CASE WHEN p_ina_ok THEN 'OK' ELSE 'ERR' END,
        CASE WHEN p_bh_ok THEN 'OK' ELSE 'ERR' END,
        CASE WHEN p_temp_ok THEN 'OK' ELSE 'ERR' END
      )
    );
  END IF;

  IF v_is_alarm AND NOT v_prev_alarm THEN
    INSERT INTO public.activity_logs (device_id, actor_type, actor_name, event_type, description)
    VALUES (
      p_device_id,
      'ESP32',
      p_device_id,
      'ALARM_OVERHEAT',
      format('Suhu panel mencapai %s C (melewati ambang %s C)', round(v_safe_temp::numeric, 1), v_config.temp_threshold_alarm)
    );
  END IF;

  -- 8. Kembalikan status & konfigurasi ke ESP32
  RETURN jsonb_build_object(
    'status', 'ok',
    'server_time', NOW(),
    'energy_wh', round(v_energy_wh::numeric, 3),
    'temp_threshold_warn', v_config.temp_threshold_warn,
    'temp_threshold_alarm', v_config.temp_threshold_alarm,
    'log_interval_seconds', v_config.log_interval_seconds
  );
END;
$$;

REVOKE ALL ON FUNCTION public.sync_solar_telemetry(
  TEXT, TEXT, DOUBLE PRECISION, DOUBLE PRECISION, DOUBLE PRECISION,
  DOUBLE PRECISION, DOUBLE PRECISION, BOOLEAN, BOOLEAN, BOOLEAN,
  BIGINT, BOOLEAN, INTEGER
) FROM PUBLIC;

GRANT EXECUTE ON FUNCTION public.sync_solar_telemetry(
  TEXT, TEXT, DOUBLE PRECISION, DOUBLE PRECISION, DOUBLE PRECISION,
  DOUBLE PRECISION, DOUBLE PRECISION, BOOLEAN, BOOLEAN, BOOLEAN,
  BIGINT, BOOLEAN, INTEGER
) TO anon, authenticated;

-- ----------------------------------------------------------------------------
-- 9. RPC ADMIN UNTUK RESET DATA UJI LOGGING SEBELUM PENGAMBILAN DATA RESMI
-- ----------------------------------------------------------------------------
CREATE OR REPLACE FUNCTION public.reset_solar_logs(p_device_id TEXT)
RETURNS JSONB
LANGUAGE plpgsql
SECURITY DEFINER
SET search_path = public
AS $$
DECLARE
  v_deleted INTEGER := 0;
BEGIN
  IF public.get_my_role() NOT IN ('admin', 'operator') THEN
    RAISE EXCEPTION 'Hanya Admin atau Operator yang dapat mereset data uji' USING ERRCODE = '42501';
  END IF;

  DELETE FROM public.telemetry_logs WHERE device_id = p_device_id;
  GET DIAGNOSTICS v_deleted = ROW_COUNT;

  UPDATE public.solar_state
  SET energy_wh = 0, updated_at = NOW()
  WHERE device_id = p_device_id;

  INSERT INTO public.activity_logs (device_id, actor_type, actor_name, event_type, description)
  VALUES (p_device_id, 'USER', COALESCE((SELECT email FROM auth.users WHERE id = auth.uid()), 'Admin'), 'DATA_RESET',
          format('Reset %s baris data logging uji & akumulator energi (0 Wh)', v_deleted));

  RETURN jsonb_build_object('status', 'ok', 'deleted_rows', v_deleted);
END;
$$;

REVOKE ALL ON FUNCTION public.reset_solar_logs(TEXT) FROM PUBLIC;
GRANT EXECUTE ON FUNCTION public.reset_solar_logs(TEXT) TO authenticated;

-- ----------------------------------------------------------------------------
-- 10. ROW LEVEL SECURITY (RLS) POLICIES
-- ----------------------------------------------------------------------------
ALTER TABLE public.user_roles ENABLE ROW LEVEL SECURITY;
ALTER TABLE public.devices ENABLE ROW LEVEL SECURITY;
ALTER TABLE public.solar_state ENABLE ROW LEVEL SECURITY;
ALTER TABLE public.solar_config ENABLE ROW LEVEL SECURITY;
ALTER TABLE public.telemetry_logs ENABLE ROW LEVEL SECURITY;
ALTER TABLE public.activity_logs ENABLE ROW LEVEL SECURITY;

DROP POLICY IF EXISTS "User baca role sendiri atau admin baca semua" ON public.user_roles;
CREATE POLICY "User baca role sendiri atau admin baca semua"
  ON public.user_roles FOR SELECT TO authenticated
  USING (user_id = auth.uid() OR public.get_my_role() = 'admin');

-- Dashboard bersifat publik (read-only bagi semua pengunjung / dosen / kelompok)
-- sedangkan modifikasi konfigurasi & perangkat dikunci untuk Admin/Operator.
DROP POLICY IF EXISTS "Publik baca devices" ON public.devices;
CREATE POLICY "Publik baca devices"
  ON public.devices FOR SELECT TO anon, authenticated
  USING (TRUE);

DROP POLICY IF EXISTS "Admin kelola devices" ON public.devices;
CREATE POLICY "Admin kelola devices"
  ON public.devices FOR ALL TO authenticated
  USING (public.get_my_role() = 'admin')
  WITH CHECK (public.get_my_role() = 'admin');

DROP POLICY IF EXISTS "Publik baca solar_state" ON public.solar_state;
CREATE POLICY "Publik baca solar_state"
  ON public.solar_state FOR SELECT TO anon, authenticated
  USING (TRUE);

DROP POLICY IF EXISTS "Publik baca solar_config" ON public.solar_config;
CREATE POLICY "Publik baca solar_config"
  ON public.solar_config FOR SELECT TO anon, authenticated
  USING (TRUE);

DROP POLICY IF EXISTS "Admin dan operator update solar_config" ON public.solar_config;
CREATE POLICY "Admin dan operator update solar_config"
  ON public.solar_config FOR UPDATE TO authenticated
  USING (public.get_my_role() IN ('admin', 'operator'))
  WITH CHECK (public.get_my_role() IN ('admin', 'operator'));

DROP POLICY IF EXISTS "Publik baca telemetry_logs" ON public.telemetry_logs;
CREATE POLICY "Publik baca telemetry_logs"
  ON public.telemetry_logs FOR SELECT TO anon, authenticated
  USING (TRUE);

DROP POLICY IF EXISTS "Publik baca activity_logs" ON public.activity_logs;
CREATE POLICY "Publik baca activity_logs"
  ON public.activity_logs FOR SELECT TO anon, authenticated
  USING (TRUE);

-- ----------------------------------------------------------------------------
-- 11. AKTIVASI SUPABASE REALTIME PUBLICATION
-- ----------------------------------------------------------------------------
DO $$
BEGIN
  IF NOT EXISTS (
    SELECT 1 FROM pg_publication_tables
    WHERE pubname = 'supabase_realtime' AND schemaname = 'public' AND tablename = 'solar_state'
  ) THEN
    ALTER PUBLICATION supabase_realtime ADD TABLE public.solar_state;
  END IF;

  IF NOT EXISTS (
    SELECT 1 FROM pg_publication_tables
    WHERE pubname = 'supabase_realtime' AND schemaname = 'public' AND tablename = 'solar_config'
  ) THEN
    ALTER PUBLICATION supabase_realtime ADD TABLE public.solar_config;
  END IF;

  IF NOT EXISTS (
    SELECT 1 FROM pg_publication_tables
    WHERE pubname = 'supabase_realtime' AND schemaname = 'public' AND tablename = 'activity_logs'
  ) THEN
    ALTER PUBLICATION supabase_realtime ADD TABLE public.activity_logs;
  END IF;
END $$;

-- ----------------------------------------------------------------------------
-- 12. DATA AWAL (SEED) NODE PERANGKAT SOLAR MONITOR
-- ----------------------------------------------------------------------------
INSERT INTO public.devices (device_id, name, location, panel_spec, device_secret_hash, is_active)
VALUES (
  'ESP32_SOLAR_01',
  'Solar Panel Node 01',
  'Area Pengujian Panel Surya',
  'INA219 (V/mA/W) + BH1750 (Lux) + DS18B20 (Temp) + LCD 16x2 I2C',
  NULL,
  TRUE
)
ON CONFLICT (device_id) DO NOTHING;

INSERT INTO public.solar_config (device_id)
VALUES ('ESP32_SOLAR_01')
ON CONFLICT (device_id) DO NOTHING;

INSERT INTO public.solar_state (device_id, wifi_connected)
VALUES ('ESP32_SOLAR_01', FALSE)
ON CONFLICT (device_id) DO NOTHING;
