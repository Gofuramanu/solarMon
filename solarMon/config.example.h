#ifndef SOLARMON_CONFIG_H
#define SOLARMON_CONFIG_H

// ============================================================================
// KONFIGURASI FIRMWARE ESP32 SOLARMONITOR (SUPABASE EDITION)
// Salin file ini menjadi "config.h" lalu isi kredensial WiFi & Supabase Anda.
// ============================================================================

// 1. Kredensial WiFi
#define WIFI_SSID           "NAMA_WIFI_ANDA"
#define WIFI_PASSWORD       "PASSWORD_WIFI_ANDA"

// 2. Kredensial Supabase Backend
#define SUPABASE_URL        "https://PROJECT_ID_ANDA.supabase.co"
#define SUPABASE_ANON_KEY   "SUPABASE_ANON_PUBLIC_KEY_ANDA"

// 3. Identitas Node (DEVICE_SECRET_KEY opsional, biarkan "" jika tanpa kunci)
#define DEVICE_ID           "ESP32_SOLAR_01"
#define DEVICE_SECRET_KEY   ""

// 4. Definisi Pinout Hardware
#define SDA_PIN             21
#define SCL_PIN             22
#define ONE_WIRE_BUS        4
#define LCD_ADDRESS         0x27

// 5. Interval Siklus (ms)
#define SENSOR_INTERVAL_MS  2000UL
#define LCD_INTERVAL_MS     3000UL
#define SYNC_INTERVAL_MS    5000UL

#endif
