/**
 * ============================================================================
 * KONFIGURASI DASHBOARD WEB SOLARMONITOR - TEMPLATE (.example)
 * ============================================================================
 * Salin menjadi "js/config.js" jika ingin langsung menyetel koneksi default
 * untuk seluruh pengunjung tanpa perlu mengisi Settings di browser.
 * Catatan Keamanan:
 * - JANGAN PERNAH memasukkan Supabase service_role key atau password database.
 * - Hanya gunakan anon public key yang dilindungi Row Level Security (RLS).
 * ============================================================================
 */

window.APP_CONFIG = {
  // Pilihan mode awal: 'simulator' | 'supabase'
  mode: "simulator",

  supabase: {
    url: "https://PROJECT_ID_ANDA.supabase.co",
    anonKey: "SUPABASE_ANON_PUBLIC_KEY_ANDA",
    deviceId: "ESP32_SOLAR_01"
  }
};
