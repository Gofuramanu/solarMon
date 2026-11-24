#include <Wire.h>
#include <WiFi.h>
#include <WiFiClientSecure.h>
#include <HTTPClient.h>
#include <Adafruit_INA219.h>
#include <BH1750.h>
#include <OneWire.h>
#include <DallasTemperature.h>
#include <LiquidCrystal_I2C.h>

// ==========================================
// 1. KONFIGURASI WI-FI & SERVER LARAVEL
// ==========================================

const char* WIFI_SSID     = "indihum";
const char* WIFI_PASSWORD = "pakrw098765";

// IP Wi-Fi laptop lokal: "http://192.168.100.17:8000/api/sensor-data"
// Jika sudah deploy ke Vercel, ganti ke URL HTTPS Vercel Anda, misalnya:
// "https://solar-monitoring-anda.vercel.app/api/sensor-data"
const char* API_URL       = "http://192.168.100.17:8000/api/sensor-data";
const char* DEVICE_ID     = "ESP32_SOLAR_01";
const char* API_TOKEN     = "slr_dev_esp32_solar_01_token_2026";

// ==========================================
// 2. KONFIGURASI PIN ESP32
// ==========================================

#define SDA_PIN 21
#define SCL_PIN 22
#define ONE_WIRE_BUS 4

// ==========================================
// 3. INISIALISASI KOMPONEN
// ==========================================

Adafruit_INA219 ina219;
BH1750 lightMeter;

OneWire oneWire(ONE_WIRE_BUS);
DallasTemperature ds18b20(&oneWire);

LiquidCrystal_I2C lcd(0x27, 16, 2);

// ==========================================
// 4. VARIABEL SENSOR & STATUS JARINGAN
// ==========================================

float voltage = 0.0;
float current = 0.0;
float power = 0.0;        // dalam mW untuk tampilan LCD & Serial
float powerWatt = 0.0;    // dalam Watt untuk dikirim ke REST API Laravel
float temperature = 0.0;
float lux = 0.0;

bool inaOK = false;
bool bhOK = false;
bool tempOK = false;
String apiStatusText = "WAIT";

// ==========================================
// 5. VARIABEL WAKTU
// ==========================================

unsigned long previousSensor = 0;
unsigned long previousLCD = 0;
unsigned long previousSend = 0;

const unsigned long sensorInterval = 2000;
const unsigned long lcdInterval = 3000;
const unsigned long sendInterval = 5000;

int lcdPage = 0;

// ==========================================
// 6. KONEKSI WI-FI
// ==========================================

void connectWiFi() {

  if (WiFi.status() == WL_CONNECTED) {
    return;
  }

  Serial.print("[WiFi] Menghubungkan ke ");
  Serial.println(WIFI_SSID);

  WiFi.mode(WIFI_STA);
  WiFi.begin(WIFI_SSID, WIFI_PASSWORD);

  unsigned long startAttempt = millis();
  while (WiFi.status() != WL_CONNECTED && millis() - startAttempt < 12000) {
    delay(500);
    Serial.print(".");
  }
  Serial.println();

  if (WiFi.status() == WL_CONNECTED) {
    Serial.print("[WiFi] Terhubung! IP ESP32: ");
    Serial.println(WiFi.localIP());
  } else {
    Serial.println("[WiFi] Gagal terhubung, akan dicoba ulang.");
  }
}

// ==========================================
// 7. PEMBACAAN SENSOR
// ==========================================

void scanI2CBus() {
  Serial.println("[I2C] Memindai perangkat pada SDA=21, SCL=22...");
  byte foundCount = 0;

  for (byte address = 1; address < 127; address++) {
    Wire.beginTransmission(address);
    byte error = Wire.endTransmission();

    if (error == 0) {
      Serial.print("[I2C] Terdeteksi alamat: 0x");
      if (address < 16) Serial.print("0");
      Serial.print(address, HEX);

      if (address == 0x40 || address == 0x41 || address == 0x44 || address == 0x45) {
        Serial.print(" (Kemungkinan INA219)");
      } else if (address == 0x23 || address == 0x5C) {
        Serial.print(" (Kemungkinan BH1750)");
      } else if (address == 0x27 || address == 0x3F) {
        Serial.print(" (Kemungkinan LCD 16x2)");
      }
      Serial.println();
      foundCount++;
    }
  }

  if (foundCount == 0) {
    Serial.println("[I2C] TIDAK ADA perangkat I2C terdeteksi! Cek kabel SDA(21), SCL(22), VCC(3V3), dan GND.");
  }
}

void initSensors() {
  if (!inaOK) {
    inaOK = ina219.begin();
    if (inaOK) {
      ina219.setCalibration_32V_2A();
      Serial.println("[INIT] INA219 BERHASIL (0x40)");
    }
  }

  if (!bhOK) {
    bhOK = lightMeter.begin(BH1750::CONTINUOUS_HIGH_RES_MODE, 0x23);
    if (!bhOK) {
      bhOK = lightMeter.begin(BH1750::CONTINUOUS_HIGH_RES_MODE, 0x5C);
    }
    if (bhOK) {
      Serial.println("[INIT] BH1750 BERHASIL");
    }
  }
}

void readSensors() {

  // Coba deteksi ulang sensor I2C jika sebelumnya terputus/longgar
  if (!inaOK || !bhOK) {
    initSensors();
  }

  // -------- INA219 --------

  if (inaOK) {

    float busVoltage = ina219.getBusVoltage_V();

    float shuntVoltage =
      ina219.getShuntVoltage_mV() / 1000.0;

    current = ina219.getCurrent_mA();

    // Tegangan pada VIN+
    voltage = busVoltage + shuntVoltage;

    // Redam noise negatif sangat kecil saat panel gelap / tanpa beban
    if (voltage < 0.0 && voltage > -0.1) voltage = 0.0;
    if (current < 0.0 && current > -2.0) current = 0.0;

    // Daya dalam mW (untuk LCD) & Watt (untuk API Laravel)
    power = voltage * current;
    if (power < 0.0) power = 0.0;
    powerWatt = power / 1000.0;

  }

  // -------- DS18B20 --------

  ds18b20.requestTemperatures();

  temperature = ds18b20.getTempCByIndex(0);

  tempOK = (temperature != DEVICE_DISCONNECTED_C) && (temperature >= -40.0 && temperature <= 125.0);

  // -------- BH1750 --------

  if (bhOK) {
    lux = lightMeter.readLightLevel();
  }

  // ========================================
  // SERIAL MONITOR
  // ========================================

  Serial.println();
  Serial.println("================================");
  Serial.println("     SOLAR PANEL MONITORING");
  Serial.println("================================");

  Serial.print("Tegangan       : ");

  if (inaOK) {
    Serial.print(voltage, 2);
    Serial.println(" V");
  } else {
    Serial.println("Sensor Error");
  }

  Serial.print("Arus           : ");

  if (inaOK) {
    Serial.print(current, 2);
    Serial.println(" mA");
  } else {
    Serial.println("Sensor Error");
  }

  Serial.print("Daya           : ");

  if (inaOK) {
    Serial.print(power, 2);
    Serial.print(" mW (");
    Serial.print(powerWatt, 4);
    Serial.println(" W)");
  } else {
    Serial.println("Sensor Error");
  }

  Serial.print("Suhu           : ");

  if (tempOK) {
    Serial.print(temperature, 2);
    Serial.println(" C");
  } else {
    Serial.println("Sensor Error");
  }

  Serial.print("Intensitas     : ");

  if (bhOK && lux >= 0) {
    Serial.print(lux, 2);
    Serial.println(" Lux");
  } else {
    Serial.println("Sensor Error");
  }

  Serial.print("WiFi / API     : ");
  Serial.print(WiFi.status() == WL_CONNECTED ? "CONNECTED" : "OFFLINE");
  Serial.print(" / ");
  Serial.println(apiStatusText);

  Serial.println("================================");
}

// ==========================================
// 8. KIRIM DATA KE REST API LARAVEL
// ==========================================

void sendSensorDataToServer() {

  bool allValid = inaOK && tempOK && bhOK
                  && !isnan(voltage) && !isnan(current) && !isnan(powerWatt)
                  && !isnan(temperature) && !isnan(lux)
                  && voltage >= 0.0 && current >= 0.0 && powerWatt >= 0.0 && lux >= 0.0;

  if (!allValid) {
    Serial.println("[API] Batal kirim: ada sensor belum terbaca / nilai tidak valid.");
    apiStatusText = "SNS_ERR";
    return;
  }

  if (WiFi.status() != WL_CONNECTED) {
    connectWiFi();
    if (WiFi.status() != WL_CONNECTED) {
      apiStatusText = "NO_WIFI";
      return;
    }
  }

  char jsonPayload[256];
  snprintf(
    jsonPayload,
    sizeof(jsonPayload),
    "{\"device_id\":\"%s\",\"voltage\":%.3f,\"current\":%.2f,\"power\":%.4f,\"temperature\":%.2f,\"lux\":%.2f}",
    DEVICE_ID, voltage, current, powerWatt, temperature, lux
  );

  HTTPClient http;
  WiFiClientSecure secureClient;
  String endpointUrl = String(API_URL);
  bool began = false;

  if (endpointUrl.startsWith("https://")) {
    secureClient.setInsecure(); // Mengizinkan koneksi HTTPS ke domain publik Vercel
    began = http.begin(secureClient, API_URL);
  } else {
    began = http.begin(API_URL);
  }

  if (!began) {
    Serial.println("[API] Gagal inisialisasi koneksi HTTP/HTTPS.");
    apiStatusText = "HTTP_INIT";
    return;
  }

  http.setTimeout(8000);
  http.addHeader("Content-Type", "application/json");
  http.addHeader("Accept", "application/json");
  http.addHeader("Authorization", String("Bearer ") + API_TOKEN);

  Serial.print("[API] POST -> ");
  Serial.println(jsonPayload);

  int httpCode = http.POST(jsonPayload);
  if (httpCode > 0) {
    String response = http.getString();
    Serial.printf("[API] HTTP %d: %s\n", httpCode, response.c_str());
    apiStatusText = (httpCode == 201 || httpCode == 200) ? "OK:201" : ("ERR:" + String(httpCode));
  } else {
    Serial.printf("[API] Gagal koneksi: %s\n", http.errorToString(httpCode).c_str());
    apiStatusText = "HTTP_ERR";
  }

  http.end();
}

// ==========================================
// 9. TAMPILAN LCD 16x2
// ==========================================

void displayLCD() {

  lcd.clear();

  // HALAMAN 1: TEGANGAN DAN ARUS
  if (lcdPage == 0) {

    lcd.setCursor(0, 0);
    lcd.print("V:");

    if (inaOK) {
      lcd.print(voltage, 2);
      lcd.print(" V");
    } else {
      lcd.print("ERROR");
    }

    lcd.setCursor(0, 1);
    lcd.print("I:");

    if (inaOK) {
      lcd.print(current, 1);
      lcd.print(" mA");
    } else {
      lcd.print("ERROR");
    }
  }

  // HALAMAN 2: DAYA DAN SUHU
  else if (lcdPage == 1) {

    lcd.setCursor(0, 0);
    lcd.print("P:");

    if (inaOK) {
      lcd.print(powerWatt, 2);
      lcd.print(" W");
    } else {
      lcd.print("ERROR");
    }

    lcd.setCursor(0, 1);
    lcd.print("T:");

    if (tempOK) {
      lcd.print(temperature, 1);
      lcd.print((char)223);
      lcd.print("C");
    } else {
      lcd.print("ERROR");
    }
  }

  // HALAMAN 3: INTENSITAS CAHAYA
  else if (lcdPage == 2) {

    lcd.setCursor(0, 0);
    lcd.print("LIGHT INTENSITY");

    lcd.setCursor(0, 1);

    if (bhOK && lux >= 0) {
      lcd.print(lux, 0);
      lcd.print(" Lux");
    } else {
      lcd.print("Sensor Error");
    }
  }

  // HALAMAN 4: STATUS WI-FI & SERVER API
  else if (lcdPage == 3) {

    lcd.setCursor(0, 0);
    lcd.print(WiFi.status() == WL_CONNECTED ? "WiFi: CONNECTED" : "WiFi: OFFLINE");

    lcd.setCursor(0, 1);
    lcd.print("API: ");
    lcd.print(apiStatusText);
  }

  // PERGANTIAN HALAMAN
  lcdPage++;
  if (lcdPage > 3) {
    lcdPage = 0;
  }
}

// ==========================================
// 10. SETUP
// ==========================================

void setup() {

  Serial.begin(115200);

  // I2C ESP32
  Wire.begin(SDA_PIN, SCL_PIN);
  Wire.setTimeOut(50);

  // LCD
  lcd.init();
  // Pastikan pin I2C tetap di GPIO 21/22 jika library LCD memanggil Wire.begin() internal
  Wire.begin(SDA_PIN, SCL_PIN);
  Wire.setTimeOut(50);
  lcd.backlight();

  lcd.setCursor(0, 0);
  lcd.print("SOLAR MONITOR");

  lcd.setCursor(0, 1);
  lcd.print("Initializing...");

  delay(200);
  scanI2CBus();

  // INA219 & BH1750
  initSensors();

  if (!inaOK) {
    Serial.println("INA219 TIDAK TERDETEKSI (Pastikan alamat 0x40 & kabel SDA/SCL/3V3/GND)");
  }
  if (!bhOK) {
    Serial.println("BH1750 TIDAK TERDETEKSI (Pastikan alamat 0x23/0x5C & kabel SDA/SCL/3V3/GND)");
  }

  // DS18B20
  ds18b20.begin();
  ds18b20.setResolution(10);
  Serial.print("[DS18B20] Jumlah sensor 1-Wire terdeteksi: ");
  Serial.println(ds18b20.getDeviceCount());
  if (ds18b20.getDeviceCount() == 0) {
    Serial.println("[DS18B20] TIDAK TERDETEKSI! Cek GPIO 4, suplai 3V3, GND, dan resistor pull-up 4.7k Ohm ke 3V3.");
  }

  // WI-FI
  lcd.clear();
  lcd.setCursor(0, 0);
  lcd.print("Connecting WiFi");
  connectWiFi();

  // STATUS AWAL
  lcd.clear();

  lcd.setCursor(0, 0);
  lcd.print("SYSTEM READY");

  lcd.setCursor(0, 1);
  lcd.print("SOLAR MONITOR");

  Serial.println();
  Serial.println("SYSTEM SOLAR MONITORING READY");

  delay(1500);

  // PEMBACAAN & KIRIM PERTAMA
  readSensors();
  displayLCD();

  previousSensor = millis();
  previousLCD = millis();
  previousSend = millis();
}

// ==========================================
// 11. LOOP
// ==========================================

void loop() {

  unsigned long currentMillis = millis();

  // BACA SENSOR SETIAP 2 DETIK
  if (currentMillis - previousSensor >= sensorInterval) {

    previousSensor = currentMillis;

    readSensors();
  }

  // GANTI LCD SETIAP 3 DETIK
  if (currentMillis - previousLCD >= lcdInterval) {

    previousLCD = currentMillis;

    displayLCD();
  }

  // KIRIM DATA KE SERVER LARAVEL SETIAP 5 DETIK
  if (currentMillis - previousSend >= sendInterval) {

    previousSend = currentMillis;

    sendSensorDataToServer();
  }
}
