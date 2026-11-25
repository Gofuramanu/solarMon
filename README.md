# SolarMonitor - Sistem Monitoring Panel Surya IoT (ESP32 + Supabase + PWA)

Sistem monitoring parameter kelistrikan (tegangan, arus, daya, akumulasi energi), intensitas cahaya matahari, dan temperatur permukaan panel surya berbasis **ESP32**, **Supabase (PostgreSQL + Realtime + RPC + Auth)**, dan **Dashboard Web Statis + Mobile PWA** yang di-deploy instan di **Vercel Edge CDN**.

> Arsitektur versi sebelumnya (Laravel 11 + TiDB Serverless) diarsipkan aman pada branch [`legacy-laravel`](../../tree/legacy-laravel). Versi utama pada branch `main` menggunakan konsep **Static Web App + Supabase** (sama seperti arsitektur [PowerHouse](https://github.com/Gofuramanu/PowerHouse)) sehingga bebas *cold-start timeout*, terbuka dalam < 0.5 detik di desktop & HP, serta siap *real-time streaming*.

---

## 1. Spesifikasi Komponen Hardware & Pinout ESP32

Berdasarkan firmware [solarMon/solarMon.ino](solarMon/solarMon.ino):

| Komponen | Parameter Diukur / Fungsi | Antarmuka / Pin ESP32 | Alamat / Catatan |
|---|---|---|---|
| **ESP32 DevKit V1** | Mikrokontroler + Wi-Fi STA | `3V3`, `GND`, `GPIO 21`, `GPIO 22`, `GPIO 4` | HTTPS Client (`WiFiClientSecure`) + Watchdog Timer 20s |
| **INA219** | Tegangan (`V`), Arus (`mA`), Daya (`W` / `mW`) | I2C (`SDA -> GPIO 21`, `SCL -> GPIO 22`) | Alamat `0x40` |
| **BH1750** | Intensitas Cahaya (`Lux`) | I2C (`SDA -> GPIO 21`, `SCL -> GPIO 22`) | Alamat `0x23` (`ADDR -> GND`) atau `0x5C` |
| **DS18B20 Waterproof** | Temperatur Panel Surya (`C`) | 1-Wire (`DQ -> GPIO 4`) | Wajib resistor pull-up `4.7k Ohm` antara `GPIO 4` dan `3V3` |
| **LCD 16x2 I2C** | Penampil Lokal 4 Halaman | I2C (`SDA -> GPIO 21`, `SCL -> GPIO 22`) | Alamat `0x27` |

---

## 2. Struktur Proyek

```text
sistem monitoring/
├── index.html                      # Dashboard Web 5 Halaman + PWA Modal
├── css/
│   └── styles.css                  # Tema Monokrom Full Black (Dark) & Clean White (Light)
├── js/
│   ├── app.js                      # Controller UI, Chart.js, Simulator & Supabase Realtime
│   ├── config.js                   # Konfigurasi default aplikasi web
│   └── config.example.js           # Template konfigurasi Supabase frontend
├── assets/
│   ├── icon.svg                    # Ikon vektor aplikasi
│   ├── icon-192.png                # Ikon PWA Android / iOS (192x192)
│   └── icon-512.png                # Ikon PWA Splash (512x512)
├── database/
│   └── supabase_schema.sql         # Skema PostgreSQL, RPC sync_solar_telemetry, RLS & Realtime
├── solarMon/
│   ├── solarMon.ino                # Firmware ESP32 (INA219, BH1750, DS18B20, LCD, Supabase RPC)
│   └── config.example.h            # Template WiFi & Supabase untuk ESP32
├── manifest.json                   # Konfigurasi Install PWA Mobile
├── sw.js                           # Service Worker PWA
└── vercel.json                     # Konfigurasi Static Hosting & Security Headers Vercel
```

---

## 3. Menjalankan Lokal & Mode Simulasi

Jalankan server statis sederhana di folder proyek:
```bash
npx serve .
```
Atau dengan Python:
```bash
python -m http.server 3000
```
Buka `http://localhost:3000`. Secara default dashboard aktif dalam **Mode Simulasi Interaktif** sehingga seluruh grafik kelistrikan, intensitas cahaya, suhu panel, data logging, export CSV, dan ganti tema Dark/Light langsung dapat diuji tanpa hardware.

---

## 4. Setup Supabase Cloud (Gratis Permanen & Real-Time)

1. Buka [supabase.com](https://supabase.com) dan buat Project baru (pilih region terdekat: **Singapore `ap-southeast-1`**).
2. Buka menu **SQL Editor**, salin seluruh isi file [database/supabase_schema.sql](database/supabase_schema.sql), lalu klik **Run**.
   Script ini otomatis membuat:
   - Tabel `user_roles`, `devices`, `solar_state`, `solar_config`, `telemetry_logs`, dan `activity_logs`.
   - Fungsi RPC `sync_solar_telemetry(...)` (menerima data ESP32, menolak sensor error `-127C`, dan menghitung akumulasi energi **Wh** otomatis via *Trapezoidal Rule*).
   - Publikasi **Supabase Realtime** serta kebijakan **Row Level Security (RLS)** (baca publik tanpa login agar praktis diakses kelompok/dosen; edit konfigurasi & reset data khusus Admin).
3. *(Opsional)* Buat akun Admin di menu **Authentication -> Users -> Add User**, lalu jalankan di SQL Editor:
   ```sql
   UPDATE public.user_roles SET role = 'admin' WHERE email = 'email_admin@domain.com';
   ```
4. Buka **Project Settings -> API**, salin **Project URL** dan **`anon` public key**.
5. Masukkan keduanya ke salah satu cara berikut:
   - **Cara A (Semua Pengunjung Langsung Terhubung)**: Edit [js/config.js](js/config.js) dengan `mode: "supabase"`, `url`, dan `anonKey`, lalu commit & push ke GitHub (Vercel otomatis redeploy dalam hitungan detik).
   - **Cara B (Dari Browser)**: Buka halaman **Settings** di dashboard, pilih **Supabase Cloud**, lalu tempel Project URL dan Anon Public Key.

---

## 5. Upload Firmware ESP32

1. Buka `solarMon/solarMon.ino` di Arduino IDE.
2. Pastikan library berikut terpasang: `Adafruit INA219`, `BH1750` (Christopher Laws), `OneWire`, `DallasTemperature`, `LiquidCrystal I2C`.
3. Isi WiFi dan kredensial Supabase (`SUPABASE_URL` & `SUPABASE_ANON_KEY`) di file `solarMon/config.h` (gunakan `solarMon/config.example.h` sebagai template).
4. Upload ke ESP32 DevKit V1 dan buka **Serial Monitor (115200 baud)** untuk memastikan muncul log `[SYNC OK] HTTP 200`.

---

## 6. Pasang Aplikasi di HP (Mobile PWA)

1. Buka URL Vercel Anda lewat Google Chrome (Android) atau Safari (iPhone).
2. Ketuk tombol **Install** di header atas (atau pilih *Add to Home screen* pada menu browser).
3. Aplikasi siap dibuka fullscreen dari layar utama HP.
