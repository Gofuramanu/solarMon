# DESIGN.md - SolarMonitor IoT (Desktop Web & Mobile PWA)

## Design Read
Reading this as: real-time solar telemetry dashboard and installable mobile web app (PWA) for electrical engineering lab operators and public viewers, crafted in the owner's Cobalt-on-White technical instrumentation aesthetic (soft alabaster `#F8F9FF` canvas, pure white `rounded-3xl` elevated cards, deep cobalt `#00288E -> #1E40AF` focal hero panels, and light tabular numeric typography in `Plus Jakarta Sans`), dial ENERGY 2 / RHYTHM 2 / MOTION 1.

## Dials
- **ENERGY: 2 (Balanced)** - Authoritative cobalt gradient focal card for live output power against calm white instrumentation cards.
- **RHYTHM: 2 (Varied with structure)** - Mirrors the owner's Desktop 3-row bento layout (Top 4 KPI cards -> Middle 5/3/4 profile/node/sensors row -> Bottom 6/3/3 logs/energy/live-control row) while reflowing into the owner's Mobile PWA layout (Hero Power -> 2x2 supporting cards -> Power Bar Chart -> Node Telemetry Strip + bottom navigation).
- **MOTION: 1 (Restrained)** - Subtle border, color, and active states only. Zero `animate-pulse`, zero bouncing chrome, full `prefers-reduced-motion: reduce` support.

## Palette (WCAG AA Verified via `contrast-check.py`)
Maximum 2 core colors + semantic status accents + neutrals (R-29):
- **Neutrals**:
  - Canvas: `#F8F9FF`
  - Surface: `#FFFFFF`
  - Primary Ink: `#0F172A` (`16.99:1` on `#F8F9FF` - PASS)
  - Secondary Ink: `#444653` (`8.90:1` on `#F8F9FF`, `9.35:1` on `#FFFFFF` - PASS)
  - Border / Divider: `#E2E8F0` / `#C4C5D5`
- **Core Primary (Deep Cobalt Instrumentation)**:
  - Deep Cobalt: `#00288E` (`#FFFFFF` text `12.40:1` PASS, `#DBEAFE` text `10.16:1` PASS)
  - Primary Cobalt: `#1E40AF` (`#FFFFFF` text `8.72:1` PASS, `#DBEAFE` text `7.15:1` PASS)
  - Soft Cobalt Surface: `#EFF4FF` (`#1E40AF` text `7.91:1` PASS)
  - Muted Cobalt Pill: `#D3E4FE` (`#00288E` text `9.62:1` PASS)
- **Functional Semantic States (paired with text labels, never color alone)**:
  - Normal / Online: `#047857` on `#ECFDF5` (`5.21:1` PASS) & `#FFFFFF` (`5.48:1` PASS)
  - Warning / High Temp / Error: `#BA1A1A` on `#FEF2F2` (`5.91:1` PASS) & `#FFFFFF` (`6.46:1` PASS)
  - Irradiance / Energy Accent: `#92400E` on `#FFFBEB` (`6.84:1` PASS)

## Typography (R-06)
- **Font Family**: `"Plus Jakarta Sans", "Segoe UI", system-ui, -apple-system, sans-serif` (self-hosted via `@fontsource/plus-jakarta-sans` so offline PWA & Vercel match FigCraft Desktop/Mobile 1:1).
- **Numeric Discipline**: `tabular-nums` on every sensor metric, clock, and table cell; light weight (`300`) for large KPI numbers and page `<h1>` matching the owner's FigCraft Desktop design.

## Radius, Elevation & Identity Motif (R-11, R-12, Part 3)
- **Radius Scale**: `24px` (`rounded-3xl`) for dashboard cards matching FigCraft `Desktop`; `16px` (`rounded-2xl`) for nested info boxes/rows; `12px` (`rounded-xl`) for nav items/buttons/inputs; `9999px` (`rounded-full`) for status & icon chips.
- **Elevation**: Ambient micro-shadow (`0 2px 12px rgba(30, 64, 175, 0.04)`) + crisp `#E2E8F0` stroke, plus `.hero-cobalt-card` gradient (`135deg, #00288E -> #1E40AF`) on primary focal panels.

## Real Hardware Scope (R-17, R-36, R-38)
All UI labels, cards, and metrics reflect ONLY verified project hardware and backend capabilities:
- Microcontroller: ESP32 DevKit V1 (30 Pin)
- Sensors: INA219 (`0x40` Bus Voltage V / Current mA / Power W), BH1750 (`0x23` Lux), DS18B20 Waterproof (`GPIO 4` One-Wire + 4.7k Ohm), LCD 16x2 I2C (`SDA GPIO 21`, `SCL GPIO 22`)
- Transport & Storage: Wi-Fi + HTTP REST API (`POST /api/sensor-data`) with Bearer/API-Key auth to Laravel + MySQL.
- Excluded (not present in system): MQTT, MicroSD, OTA, Relay cut-off, CO2 estimates, invented percentage deltas.

## Decision Log (R-31)
- **Why Self-Hosted Plus Jakarta Sans + Light Display Scale**: Reproduces the clean instrument aesthetic of the owner's `Desktop` & `Mobile` FigCraft mockups offline and online with zero font flash.
- **Why `#00288E -> #1E40AF` + `#EFF4FF` Surface Tints**: Aligns closely with the exact FigCraft hex tokens while achieving 7.15:1 to 16.99:1 WCAG AA/AAA text contrast.
- **Why Honest Padanan Replacing Fabricated Items**: Keeps every visual card slot (sidebar interval widget, global search bar, top status pill, installed sensor list, trapezoidal energy gauge, and status-badged log rows) while showing authentic ESP32 + HTTP REST API + MySQL telemetry.
