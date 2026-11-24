# SolarMonitor IoT - Sistem Monitoring Panel Surya Berbasis ESP32

Aplikasi web full-stack (**Laravel 11 + MySQL + Vite + Chart.js + DomPDF + Mobile PWA**) untuk memantau parameter kelistrikan, intensitas cahaya, dan temperatur panel surya secara real-time, menyimpan data historis (data logging), menghitung akumulasi energi listrik (Wh / kWh dengan metode *Trapezoidal Rule*), mengekspor laporan CSV/PDF, serta siap di-deploy ke **Vercel + Cloud MySQL** agar dapat diakses publik dan dipasang di smartphone.

---

## 1. Spesifikasi Hardware & Pinout ESP32

| Komponen | Parameter Diukur / Fungsi | Antarmuka / Pin ESP32 DevKit V1 | Alamat / Catatan |
|---|---|---|---|
| **ESP32 DevKit V1 (30 Pin)** | Mikrokontroler + Wi-Fi STA | `3V3`, `GND`, `GPIO 21`, `GPIO 22`, `GPIO 4` | HTTP/HTTPS REST Client |
| **INA219** | Tegangan (`V`), Arus (`mA`), Daya (`W`) | I2C (`SDA -> GPIO 21`, `SCL -> GPIO 22`) | Alamat `0x40` |
| **BH1750** | Intensitas Cahaya (`Lux`) | I2C (`SDA -> GPIO 21`, `SCL -> GPIO 22`) | Alamat `0x23` (`ADDR -> GND`) |
| **DS18B20 Waterproof** | Temperatur Permukaan Panel (`C`) | 1-Wire (`DQ -> GPIO 4`) | **Wajib** resistor pull-up `4.7k Ohm` antara `GPIO 4` dan `3V3` |
| **LCD 16x2 I2C** | Monitoring Lokal di Panel | I2C (`SDA -> GPIO 21`, `SCL -> GPIO 22`) | Alamat `0x27` (atau `0x3F`) |

Firmware tersedia di:
- [solarMon/solarMon.ino](solarMon/solarMon.ino) (sketch utama proyek)
- [firmware/esp32_solar_monitor/esp32_solar_monitor.ino](firmware/esp32_solar_monitor/esp32_solar_monitor.ino) (template referensi)

---

## 2. Arsitektur & Struktur Database MySQL

Tabel inti pada schema `solar_monitoring`:
1. **`users`**: Akun Administrator (`username`, `email`, `password`, `role`, `is_active`, `last_login_at`).
2. **`devices`**: Node ESP32 terdaftar (`device_code`, `name`, `location`, `api_token_hash` SHA-256, `api_token_prefix`, `sensors_info`, `send_interval_sec`, `offline_timeout_sec`, `last_seen_at`, `last_tx_status`, `last_tx_error`).
3. **`sensor_readings`**: Data telemetri aktual (`device_id`, `voltage`, `current`, `power`, `temperature`, `lux`, `recorded_at`), lengkap dengan indeks `idx_sensor_readings_device_id`, `idx_sensor_readings_recorded_at`, dan `idx_sensor_readings_device_recorded`.
4. **`system_settings`**: Pengaturan operasional (`site_name`, `dashboard_refresh_interval`, `default_offline_threshold`, `energy_max_gap_seconds`, `public_dashboard_enabled`), di-cache singkat melalui `Cache::remember` dan otomatis dibersihkan saat diperbarui.

---

## 3. Menjalankan Secara Lokal (Laragon / XAMPP)

1. Pastikan database MySQL `solar_monitoring` sudah dibuat dan file `.env` sesuai (lihat `.env.example`).
2. Jalankan instalasi & build:
   ```bash
   composer install
   npm install
   npm run build
   php artisan migrate --seed
   ```
3. Jalankan server agar bisa diakses dari laptop maupun ESP32 pada Wi-Fi yang sama:
   ```bash
   php artisan serve --host=0.0.0.0 --port=8000
   ```
4. Buka browser ke `http://localhost:8000` (untuk akses Admin default hasil seeder: username `admin`, password `admin12345`).

---

## 4. Dokumentasi REST API Ingesti ESP32

- **Health Check**: `GET /api/health`
- **Simpan Pembacaan Sensor**: `POST /api/sensor-data`
  - **Header**:
    - `Authorization: Bearer <API_TOKEN>` (atau `X-API-Key: <API_TOKEN>`)
    - `Content-Type: application/json`
    - `Accept: application/json`
  - **Body JSON**:
    ```json
    {
      "device_id": "ESP32_SOLAR_01",
      "voltage": 18.75,
      "current": 265.00,
      "power": 4.9688,
      "temperature": 33.1,
      "lux": 14200
    }
    ```
  - **Catatan**: Server menolak pembacaan `-127 C` (sensor DS18B20 putus), nilai di luar rentang fisika sensor, atau perangkat nonaktif, dan mencatat status error tersebut di halaman **Manajemen Perangkat**.

---

## 5. Panduan Deploy ke Vercel + Cloud MySQL Gratis (Aplikasi Publik & PWA)

Aplikasi ini sudah dilengkapi `vercel.json`, `api/index.php`, `.vercelignore`, proxy HTTPS (`trustProxies`), dan support `/tmp/storage` agar berjalan di **Vercel** (`vercel-php@0.9.0`).

### Langkah 1: Siapkan Cloud MySQL Gratis (TiDB Cloud Serverless / Aiven)
1. Buat cluster MySQL gratis di **TiDB Cloud Serverless** (https://tidbcloud.com) atau **Aiven for MySQL** (https://aiven.io), lalu buat database bernama `solar_monitoring`.
2. Catat kredensial koneksi: `DB_HOST`, `DB_PORT` (umumnya `4000` untuk TiDB Cloud), `DB_DATABASE`, `DB_USERNAME`, `DB_PASSWORD`.
3. Dari terminal laptop Anda, isi `.env` sementara dengan kredensial Cloud MySQL tersebut (atau jalankan sekali via env shell) lalu jalankan migrasi & seeder ke database cloud:
   ```bash
   php artisan migrate --seed --force
   ```
   *(Setelah migrasi ke cloud selesai, kembalikan `.env` lokal Anda ke `127.0.0.1` jika masih ingin mengembangkan secara lokal).*

### Langkah 2: Push Proyek ke GitHub & Hubungkan ke Vercel
1. Repository GitHub resmi proyek: `https://github.com/Gofuramanu/solarMon.git`.
2. Pastikan seluruh perubahan terbaru (termasuk `vercel.json`, `api/index.php`, `.vercelignore`, `public/manifest.webmanifest`, `public/sw.js`) sudah di-commit dan di-push ke branch `main` di GitHub Anda (file `.env` otomatis diabaikan oleh `.gitignore` demi keamanan).
3. Buka dashboard **Vercel** (`https://vercel.com/new`) dan impor repository GitHub `Gofuramanu/solarMon`.
4. Di menu **Settings -> Environment Variables** pada proyek Vercel, tambahkan variabel berikut untuk environment **Production**:
   - `APP_KEY`: Salin nilai `APP_KEY` dari `.env` lokal (contoh `base64:j1OMbiN0pafhaQNCZOI0DT7kdyxyLE3gGmHngdX9JAE=`).
   - `APP_URL`: URL domain Vercel Anda, misalnya `https://solarmon.vercel.app`.
   - `DB_CONNECTION`: `mysql`
   - `DB_HOST`: Host Cloud MySQL (contoh: `gateway01.ap-southeast-1.prod.aws.tidbcloud.com`)
   - `DB_PORT`: `4000`
   - `DB_DATABASE`: `test` (atau database TiDB yang Anda gunakan)
   - `DB_USERNAME`: Username TiDB Anda (contoh: `4TZpb8fbi1HAY8X.root`)
   - `DB_PASSWORD`: Password TiDB Cloud Anda
   - `MYSQL_ATTR_SSL_CA`: `true`
   - `MYSQL_ATTR_SSL_VERIFY_SERVER_CERT`: `false`
   - `ESP32_DEFAULT_TOKEN`: `slr_dev_esp32_solar_01_token_2026`
5. Klik **Deploy**. Vercel akan menjalankan build, membundel `api/index.php`, dan mengaktifkan domain publik HTTPS (`https://*.vercel.app`).

### Langkah 3: Hubungkan ESP32 ke Server Vercel (HTTPS)
Firmware [solarMon/solarMon.ino](solarMon/solarMon.ino) sudah mendukung protokol **HTTP lokal maupun HTTPS Vercel** secara otomatis lewat `WiFiClientSecure`.
Ubah baris `API_URL` di `solarMon/solarMon.ino`:
```cpp
const char* API_URL = "https://solar-monitoring-anda.vercel.app/api/sensor-data";
```
Kemudian upload ulang ke ESP32 via Arduino IDE.

### Langkah 4: Pasang Sebagai Aplikasi di HP (Mobile PWA)
Setelah web terbuka melalui link HTTPS Vercel (atau Cloudflare Tunnel):
- **Android (Chrome)**: Ketuk tombol **Menu -> Pasang Aplikasi (PWA)** atau pilih **Install app** / **Add to Home screen** dari menu Chrome.
- **iPhone / iPad (Safari)**: Ketuk ikon **Share**, lalu pilih **Add to Home Screen**.

---

## 6. Troubleshooting Singkat

- **Serial Monitor menampilkan `Sensor Error` / `I2C TIDAK TERDETEKSI`**:
  - Pastikan modul INA219, BH1750, dan LCD terhubung ke pin `SDA = GPIO 21` dan `SCL = GPIO 22` serta `GND` menyatu (*common ground*) dengan ESP32.
  - Pastikan DS18B20 memakai resistor pull-up `4.7k Ohm` antara pin Data (`GPIO 4`) dan `3V3`.
- **ESP32 mendapat error `HTTP_ERR` / `-1` saat mengirim ke server lokal**:
  - Pastikan laptop dan ESP32 terhubung ke jaringan Wi-Fi yang sama, alamat `API_URL` memakai IPv4 Wi-Fi laptop terbaru (`ipconfig`), dan Windows Firewall mengizinkan port `8000`.
- **Error `read-only file system` di Vercel**:
  - Pastikan deployment menyertakan `api/index.php`, `bootstrap/app.php`, dan `vercel.json` versi terbaru yang mengarahkan `storage` dan cache ke `/tmp`.

---

## 7. Pengujian Otomatis

Seluruh fitur API, database, grafik, ekspor CSV/PDF, autentikasi, perangkat, dan pengaturan dapat diverifikasi dengan:
```bash
php artisan test
```
