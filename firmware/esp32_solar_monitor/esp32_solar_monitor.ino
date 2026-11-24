/*
 * ============================================================================
 * SISTEM MONITORING PANEL SURYA BERBASIS ESP32 & REST API LARAVEL
 * ============================================================================
 * Hardware:
 *  - ESP32 DevKit V1 (30 Pin)
 *  - INA219  : Tegangan (V), Arus (mA), Daya (W) -> I2C (GPIO 21 SDA, GPIO 22 SCL)
 *  - BH1750  : Intensitas Cahaya (Lux)           -> I2C (GPIO 21 SDA, GPIO 22 SCL)
 *  - DS18B20 : Temperatur Panel Surya (C)        -> 1-Wire (GPIO 4 + Resistor 4.7k Ohm ke 3V3)
 *  - LCD 16x2 I2C : Monitoring Lokal             -> I2C (GPIO 21 SDA, GPIO 22 SCL)
 *
 * Library Wajib (Install via Arduino IDE Library Manager):
 *  1. Adafruit INA219 (by Adafruit)
 *  2. BH1750 (by Christopher Laws)
 *  3. OneWire (by Paul Stoffregen)
 *  4. DallasTemperature (by Miles Burton)
 *  5. LiquidCrystal I2C (by Frank de Brabander)
 * ============================================================================
 */

#include <Wire.h>
#include <WiFi.h>
#include <WiFiClientSecure.h>
#include <HTTPClient.h>
#include <Adafruit_INA219.h>
#include <BH1750.h>
#include <OneWire.h>
#include <DallasTemperature.h>
#include <LiquidCrystal_I2C.h>

// ===================== KONFIGURASI WI-FI & SERVER =====================
const char* WIFI_SSID     = "NAMA_WIFI_ANDA";
const char* WIFI_PASSWORD = "PASSWORD_WIFI_ANDA";

// Ganti IP_SERVER dengan IP lokal laptop/server (cek via ipconfig), contoh: 192.168.1.10
const char* API_URL       = "http://192.168.1.10:8000/api/sensor-data";
const char* DEVICE_ID     = "ESP32_SOLAR_01";
const char* API_TOKEN     = "slr_dev_esp32_solar_01_token_2026";

// Interval pengiriman data (5 detik) & pergantian halaman LCD (2.5 detik)
const unsigned long SEND_INTERVAL_MS = 5000;
const unsigned long LCD_INTERVAL_MS  = 2500;

// ===================== KONFIGURASI PIN & ALAMAT I2C ====================
constexpr int I2C_SDA_PIN   = 21;
constexpr int I2C_SCL_PIN   = 22;
constexpr int DS18B20_PIN   = 4;
constexpr uint8_t LCD_ADDR  = 0x27; // Ubah ke 0x3F jika LCD Anda memakai alamat 0x3F

// ===================== INISIALISASI OBJEK SENSOR ======================
Adafruit_INA219 ina219;
BH1750 lightMeter(0x23); // Alamat default BH1750 (0x23 jika ADDR ke GND/floating)
OneWire oneWire(DS18B20_PIN);
DallasTemperature tempSensor(&oneWire);
LiquidCrystal_I2C lcd(LCD_ADDR, 16, 2);

bool inaReady = false;
bool bhReady  = false;

unsigned long lastSendMillis = 0;
unsigned long lastLcdMillis  = 0;
uint8_t lcdPage = 0;
String lastHttpStatus = "WAIT";

// Data sensor terakhir untuk tampilan LCD & pengiriman API
float gVoltage     = 0.0f;
float gCurrent     = 0.0f;
float gPower       = 0.0f;
float gTemperature = 0.0f;
float gLux         = 0.0f;
bool  gDataValid   = false;

void connectWiFi() {
    if (WiFi.status() == WL_CONNECTED) return;

    Serial.print("[WiFi] Menghubungkan ke ");
    Serial.println(WIFI_SSID);

    WiFi.mode(WIFI_STA);
    WiFi.begin(WIFI_SSID, WIFI_PASSWORD);

    lcd.clear();
    lcd.setCursor(0, 0);
    lcd.print("Connecting WiFi");

    unsigned long startAttempt = millis();
    while (WiFi.status() != WL_CONNECTED && millis() - startAttempt < 15000) {
        delay(500);
        Serial.print(".");
    }
    Serial.println();

    if (WiFi.status() == WL_CONNECTED) {
        Serial.print("[WiFi] Terhubung! IP ESP32: ");
        Serial.println(WiFi.localIP());
    } else {
        Serial.println("[WiFi] Gagal terhubung, akan dicoba ulang...");
    }
}

void readSensors() {
    float voltage = 0.0f;
    float current = 0.0f;
    float power   = 0.0f;

    if (inaReady) {
        // Tegangan total sisi panel = busVoltage + (shuntVoltage / 1000)
        voltage = ina219.getBusVoltage_V() + (ina219.getShuntVoltage_mV() / 1000.0f);
        current = ina219.getCurrent_mA();
        power   = ina219.getPower_mW() / 1000.0f; // Konversi mW ke Watt

        // Redam noise negatif sangat kecil saat tidak ada beban (dark/idle)
        if (voltage < 0.0f && voltage > -0.1f) voltage = 0.0f;
        if (current < 0.0f && current > -2.0f) current = 0.0f;
        if (power   < 0.0f && power   > -0.05f) power  = 0.0f;
    }

    tempSensor.requestTemperatures();
    float tempC = tempSensor.getTempCByIndex(0);

    float lux = 0.0f;
    if (bhReady) {
        lux = lightMeter.readLightLevel();
    }

    // Validasi pembacaan sensor sebelum disimpan/dikirim
    bool tempValid = (tempC != DEVICE_DISCONNECTED_C) && (tempC >= -40.0f && tempC <= 125.0f);
    bool elecValid = inaReady && !isnan(voltage) && !isnan(current) && !isnan(power)
                     && voltage >= 0.0f && current >= 0.0f && power >= 0.0f;
    bool luxValid  = bhReady && !isnan(lux) && lux >= 0.0f;

    if (!tempValid) {
        Serial.println("[SENSOR ERROR] DS18B20 tidak terbaca (periksa GPIO 4 & resistor 4.7k Ohm).");
    }
    if (!elecValid) {
        Serial.println("[SENSOR ERROR] INA219 tidak valid / belum siap.");
    }
    if (!luxValid) {
        Serial.println("[SENSOR ERROR] BH1750 tidak valid / belum siap.");
    }

    gVoltage     = voltage;
    gCurrent     = current;
    gPower       = power;
    gTemperature = tempC;
    gLux         = lux;
    gDataValid   = tempValid && elecValid && luxValid;
}

void updateLcdDisplay() {
    lcd.clear();
    if (!gDataValid) {
        lcd.setCursor(0, 0);
        lcd.print("SENSOR ERROR!");
        lcd.setCursor(0, 1);
        lcd.print("Cek Kabel/I2C");
        return;
    }

    if (lcdPage == 0) {
        // Baris 1: V:18.5V I:250mA
        lcd.setCursor(0, 0);
        lcd.printf("V:%.2fV I:%.0fmA", gVoltage, gCurrent);
        // Baris 2: P:4.62W  API:OK
        lcd.setCursor(0, 1);
        lcd.printf("P:%.2fW  %s", gPower, lastHttpStatus.c_str());
    } else {
        // Baris 1: T:32.5C L:12500
        lcd.setCursor(0, 0);
        lcd.printf("T:%.1fC L:%.0f", gTemperature, gLux);
        // Baris 2: WiFi status
        lcd.setCursor(0, 1);
        if (WiFi.status() == WL_CONNECTED) {
            lcd.print("WiFi:OK  SolarIoT");
        } else {
            lcd.print("WiFi:OFFLINE");
        }
    }

    lcdPage = (lcdPage + 1) % 2;
}

void sendTelemetryToServer() {
    if (!gDataValid) {
        Serial.println("[API] Pengiriman dibatalkan karena pembacaan sensor tidak valid.");
        lastHttpStatus = "SNS_ERR";
        return;
    }

    if (WiFi.status() != WL_CONNECTED) {
        connectWiFi();
        if (WiFi.status() != WL_CONNECTED) {
            lastHttpStatus = "NO_WIFI";
            return;
        }
    }

    char payload[256];
    snprintf(
        payload,
        sizeof(payload),
        "{\"device_id\":\"%s\",\"voltage\":%.3f,\"current\":%.2f,\"power\":%.4f,\"temperature\":%.2f,\"lux\":%.2f}",
        DEVICE_ID, gVoltage, gCurrent, gPower, gTemperature, gLux
    );

    HTTPClient http;
    WiFiClientSecure secureClient;
    String endpointUrl = String(API_URL);
    bool began = false;

    if (endpointUrl.startsWith("https://")) {
        secureClient.setInsecure();
        began = http.begin(secureClient, API_URL);
    } else {
        began = http.begin(API_URL);
    }

    if (!began) {
        Serial.println("[API] Gagal inisialisasi HTTP/HTTPS.");
        lastHttpStatus = "INIT_ERR";
        return;
    }

    http.setTimeout(8000);
    http.addHeader("Content-Type", "application/json");
    http.addHeader("Accept", "application/json");
    http.addHeader("Authorization", String("Bearer ") + API_TOKEN);

    Serial.print("[API] Mengirim: ");
    Serial.println(payload);

    int httpCode = http.POST(payload);
    if (httpCode > 0) {
        String responseBody = http.getString();
        Serial.printf("[API] HTTP %d -> %s\n", httpCode, responseBody.c_str());
        lastHttpStatus = (httpCode == 201 || httpCode == 200) ? "API:OK" : ("ERR:" + String(httpCode));
    } else {
        Serial.printf("[API] Gagal kirim: %s\n", http.errorToString(httpCode).c_str());
        lastHttpStatus = "HTTP_ERR";
    }
    http.end();
}

void setup() {
    Serial.begin(115200);
    delay(300);

    Wire.begin(I2C_SDA_PIN, I2C_SCL_PIN);

    lcd.init();
    lcd.backlight();
    lcd.setCursor(0, 0);
    lcd.print("SolarMonitor IoT");
    lcd.setCursor(0, 1);
    lcd.print("Init Sensors...");

    inaReady = ina219.begin();
    if (!inaReady) {
        Serial.println("[INIT] Gagal mendeteksi INA219! Periksa SDA/SCL.");
    }

    bhReady = lightMeter.begin(BH1750::CONTINUOUS_HIGH_RES_MODE);
    if (!bhReady) {
        Serial.println("[INIT] Gagal mendeteksi BH1750! Periksa SDA/SCL.");
    }

    tempSensor.begin();
    connectWiFi();

    // Lakukan pembacaan awal segera setelah boot
    readSensors();
    updateLcdDisplay();
}

void loop() {
    unsigned long now = millis();

    if (now - lastLcdMillis >= LCD_INTERVAL_MS) {
        lastLcdMillis = now;
        readSensors();
        updateLcdDisplay();
    }

    if (now - lastSendMillis >= SEND_INTERVAL_MS) {
        lastSendMillis = now;
        readSensors();
        sendTelemetryToServer();
    }
}
