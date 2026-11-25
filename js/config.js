/**
 * Konfigurasi default aplikasi web SolarMonitor.
 * Ganti mode ke "supabase" dan isi url + anonKey setelah membuat proyek Supabase.
 * Konfigurasi ini juga dapat diubah langsung lewat menu Settings di browser.
 */
window.APP_CONFIG = {
  mode: "simulator",
  supabase: {
    url: "",
    anonKey: "",
    deviceId: "ESP32_SOLAR_01"
  }
};
