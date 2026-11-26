#include <Wire.h>
#include <WiFi.h>
#include <WiFiClientSecure.h>
#include <HTTPClient.h>
#include <Adafruit_INA219.h>
#include <BH1750.h>
#include <OneWire.h>
#include <DallasTemperature.h>
#include <LiquidCrystal_I2C.h>
#include <esp_task_wdt.h>
#include <math.h>

// Gunakan config.h lokal jika tersedia, atau fallback ke config.example.h
#if __has_include("config.h")
  #include "config.h"
#else
  #include "config.example.h"
#endif

// ==========================================
// 1. INISIALISASI KOMPONEN HARDWARE
// ==========================================
Adafruit_INA219 ina219;
BH1750 lightMeter;

OneWire oneWire(ONE_WIRE_BUS);
DallasTemperature ds18b20(&oneWire);

LiquidCrystal_I2C lcd(LCD_ADDRESS, 16, 2);

// ==========================================
// 2. VARIABEL SENSOR & STATUS JARINGAN
// ==========================================
float busVoltage = 0.0f;
float shuntVoltageMv = 0.0f;
float voltage = 0.0f;
float currentMa = 0.0f;
float powerMw = 0.0f;
float powerWatt = 0.0f;
float temperature = 0.0f;
float lux = 0.0f;

bool inaOK = false;
bool bhOK = false;
bool tempOK = false;
String apiStatusText = "WAIT";

// ==========================================
// 3. VARIABEL WAKTU
// ==========================================
unsigned long previousSensor = 0;
unsigned long previousLCD = 0;
unsigned long previousSend = 0;
unsigned long lastWifiAttempt = 0;
int lcdPage = 0;

// ==========================================
// 4. KONEKSI WI-FI
// ==========================================
void connectWiFiInitial() {
  Serial.print("[WiFi] Menghubungkan ke ");
  Serial.println(WIFI_SSID);

  WiFi.mode(WIFI_STA);
  WiFi.setAutoReconnect(true);
  WiFi.begin(WIFI_SSID, WIFI_PASSWORD);

  unsigned long startAttempt = millis();
  while (WiFi.status() != WL_CONNECTED && millis() - startAttempt < 12000) {
    esp_task_wdt_reset();
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

void maintainWiFi() {
  if (WiFi.status() == WL_CONNECTED) return;
  unsigned long now = millis();
  if (now - lastWifiAttempt >= 10000UL) {
    lastWifiAttempt = now;
    WiFi.disconnect(false);
    WiFi.begin(WIFI_SSID, WIFI_PASSWORD);
  }
}

// ==========================================
// 5. PEMBACAAN SENSOR (TANPA MEMOTONG ARUS KE NOL)
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
    Serial.println("[I2C] TIDAK ADA perangkat I2C terdeteksi! Cek kabel SDA(21), SCL(22), 3V3, dan GND.");
  }
}

void initSensors() {
  if (!inaOK) {
    inaOK = ina219.begin();
    if (inaOK) {
      // Kalibrasi peka untuk panel surya 5V arus kecil agar mA dan mV terbaca detail
      ina219.setCalibration_16V_400mA();
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
  if (!inaOK || !bhOK) {
    initSensors();
  }

  // -------- INA219 --------
  if (inaOK) {
    float rawBus = ina219.getBusVoltage_V();
    float rawShunt = ina219.getShuntVoltage_mV();
    float rawCurrent = ina219.getCurrent_mA();

    if (isnan(rawBus) || isnan(rawCurrent)) {
      inaOK = false;
      busVoltage = 0.0f;
      shuntVoltageMv = 0.0f;
      voltage = 0.0f;
      currentMa = 0.0f;
      powerMw = 0.0f;
      powerWatt = 0.0f;
    } else {
      busVoltage = fabs(rawBus);
      shuntVoltageMv = rawShunt;
      // Gunakan fabs() agar arus tidak terpotong jadi 0.00 saat arah kabel VIN+/VIN- bernilai minus
      voltage = fabs(busVoltage + (rawShunt / 1000.0f));
      currentMa = fabs(rawCurrent);
      powerMw = voltage * currentMa;
      powerWatt = powerMw / 1000.0f;
    }
  }

  // -------- DS18B20 --------
  ds18b20.requestTemperatures();
  float rawTemp = ds18b20.getTempCByIndex(0);
  tempOK = (!isnan(rawTemp)) && (rawTemp != DEVICE_DISCONNECTED_C) && (rawTemp >= -40.0f && rawTemp <= 125.0f);
  temperature = tempOK ? rawTemp : 0.0f;

  // -------- BH1750 --------
  if (bhOK) {
    float rawLux = lightMeter.readLightLevel();
    if (isnan(rawLux) || rawLux < 0.0f) {
      bhOK = false;
      lux = 0.0f;
    } else {
      lux = rawLux;
    }
  }

  // ========================================
  // SERIAL MONITOR (LENGKAP SEMUA PARAMETER)
  // ========================================
  Serial.println();
  Serial.println("================================");
  Serial.println("     SOLAR PANEL MONITORING");
  Serial.println("================================");
  Serial.printf("Tegangan Bus   : %.3f V\n", busVoltage);
  Serial.printf("Tegangan Shunt : %.3f mV\n", shuntVoltageMv);
  Serial.printf("Tegangan Panel : %.3f V\n", voltage);
  Serial.printf("Arus           : %.3f mA\n", currentMa);
  Serial.printf("Daya           : %.3f mW (%.4f W)\n", powerMw, powerWatt);
  Serial.printf("Suhu           : %.2f C\n", temperature);
  Serial.printf("Intensitas     : %.2f Lux\n", lux);
  Serial.printf("WiFi / Cloud   : %s / %s\n", WiFi.status() == WL_CONNECTED ? "CONNECTED" : "OFFLINE", apiStatusText.c_str());
  Serial.println("================================");
}

// ==========================================
// 6. KIRIM DATA KE SUPABASE CLOUD (HTTPS)
// ==========================================
void sendSensorDataToServer() {
  if (WiFi.status() != WL_CONNECTED) {
    maintainWiFi();
    apiStatusText = "NO_WIFI";
    return;
  }

  String baseUrl = String(SUPABASE_URL);
  String endpoint = baseUrl + "/rest/v1/rpc/sync_solar_telemetry";

  WiFiClientSecure secureClient;
  secureClient.setInsecure();

  HTTPClient http;
  if (!http.begin(secureClient, endpoint)) {
    Serial.println("[API] Gagal inisialisasi koneksi HTTPS.");
    apiStatusText = "HTTP_INIT";
    return;
  }

  http.setTimeout(6000);
  http.addHeader("Content-Type", "application/json");
  http.addHeader("apikey", SUPABASE_ANON_KEY);
  if (String(SUPABASE_ANON_KEY).startsWith("eyJ")) {
    http.addHeader("Authorization", String("Bearer ") + SUPABASE_ANON_KEY);
  }

  unsigned long uptimeSec = millis() / 1000UL;
  int rssi = WiFi.RSSI();

  char jsonPayload[512];
  snprintf(
    jsonPayload,
    sizeof(jsonPayload),
    "{\"p_device_id\":\"%s\",\"p_voltage\":%.3f,\"p_current_ma\":%.3f,\"p_power_w\":%.5f,\"p_lux\":%.2f,\"p_temperature\":%.2f,\"p_ina_ok\":%s,\"p_bh_ok\":%s,\"p_temp_ok\":%s,\"p_uptime_seconds\":%lu,\"p_wifi_connected\":true,\"p_wifi_rssi\":%d}",
    DEVICE_ID,
    voltage, currentMa, powerWatt, lux, temperature,
    inaOK ? "true" : "false",
    bhOK ? "true" : "false",
    tempOK ? "true" : "false",
    uptimeSec, rssi
  );

  Serial.print("[API] POST -> ");
  Serial.println(jsonPayload);

  int httpCode = http.POST(jsonPayload);
  if (httpCode > 0) {
    String response = http.getString();
    Serial.printf("[API] HTTP %d: %s\n", httpCode, response.c_str());
    apiStatusText = (httpCode >= 200 && httpCode < 300) ? ("OK:" + String(httpCode)) : ("ERR:" + String(httpCode));
  } else {
    Serial.printf("[API] Gagal koneksi: %s\n", http.errorToString(httpCode).c_str());
    apiStatusText = "HTTP_ERR";
  }

  http.end();
}

// ==========================================
// 7. TAMPILAN LCD 16x2
// ==========================================
void displayLCD() {
  lcd.clear();

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
      lcd.print(currentMa, 2);
      lcd.print(" mA");
    } else {
      lcd.print("ERROR");
    }
  } else if (lcdPage == 1) {
    lcd.setCursor(0, 0);
    lcd.print("P:");
    if (inaOK) {
      lcd.print(powerMw, 2);
      lcd.print(" mW");
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
  } else if (lcdPage == 2) {
    lcd.setCursor(0, 0);
    lcd.print("LIGHT INTENSITY");
    lcd.setCursor(0, 1);
    if (bhOK && lux >= 0) {
      lcd.print(lux, 1);
      lcd.print(" Lux");
    } else {
      lcd.print("Sensor Error");
    }
  } else {
    lcd.setCursor(0, 0);
    lcd.print(WiFi.status() == WL_CONNECTED ? "WiFi: CONNECTED" : "WiFi: OFFLINE");
    lcd.setCursor(0, 1);
    lcd.print("API: ");
    lcd.print(apiStatusText);
  }

  lcdPage = (lcdPage + 1) % 4;
}

// ==========================================
// 8. SETUP & LOOP
// ==========================================
void setup() {
  Serial.begin(115200);

  Wire.begin(SDA_PIN, SCL_PIN);
  Wire.setTimeOut(50);

  lcd.init();
  Wire.begin(SDA_PIN, SCL_PIN);
  Wire.setTimeOut(50);
  lcd.backlight();

  lcd.setCursor(0, 0);
  lcd.print("SOLAR MONITOR");
  lcd.setCursor(0, 1);
  lcd.print("Initializing...");

#if defined(ESP_ARDUINO_VERSION_MAJOR) && ESP_ARDUINO_VERSION_MAJOR >= 3
  esp_task_wdt_config_t wdt_config = {
    .timeout_ms = 20000,
    .idle_core_mask = (1 << portNUM_PROCESSORS) - 1,
    .trigger_panic = true
  };
  esp_task_wdt_init(&wdt_config);
#else
  esp_task_wdt_init(20, true);
#endif
  esp_task_wdt_add(NULL);

  delay(200);
  scanI2CBus();
  initSensors();

  ds18b20.begin();
  ds18b20.setResolution(10);
  Serial.print("[DS18B20] Jumlah sensor 1-Wire terdeteksi: ");
  Serial.println(ds18b20.getDeviceCount());

  lcd.clear();
  lcd.setCursor(0, 0);
  lcd.print("Connecting WiFi");
  connectWiFiInitial();

  lcd.clear();
  lcd.setCursor(0, 0);
  lcd.print("SYSTEM READY");
  lcd.setCursor(0, 1);
  lcd.print("SOLAR MONITOR");

  Serial.println();
  Serial.println("SYSTEM SOLAR MONITORING READY");

  readSensors();
  displayLCD();

  unsigned long now = millis();
  previousSensor = now;
  previousLCD = now;
  previousSend = now;
}

void loop() {
  esp_task_wdt_reset();
  unsigned long currentMillis = millis();

  maintainWiFi();

  if (currentMillis - previousSensor >= SENSOR_INTERVAL_MS) {
    previousSensor = currentMillis;
    readSensors();
  }

  if (currentMillis - previousLCD >= LCD_INTERVAL_MS) {
    previousLCD = currentMillis;
    displayLCD();
  }

  if (currentMillis - previousSend >= SYNC_INTERVAL_MS) {
    previousSend = currentMillis;
    sendSensorDataToServer();
  }
}
