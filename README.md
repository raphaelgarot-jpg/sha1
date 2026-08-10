# 🐦‍🔥 A.S.H.E.S. 2026 (Advanced Smart Home Energy & Supervision)

> Previously **S.H.A. (Smart Home Architecture)** — An ultra-fast, highly custom, lightweight smart home dashboard designed for centralized control, energy monitoring, and household management.

---

## ⚠️ Disclaimer, Portability & Language Note (Personal Project)

This repository is a personal project, custom-built to match the physical, hardware, and architectural setup of a specific residence.

### 🤖 AI-Assisted Development
Yes, I extensively use various AI models (Gemini, Claude, Devstral, Qwen, etc.) throughout this codebase. This project serves as my hands-on laboratory to learn and experiment AI-driven software engineering and Linux-administration.

### 🌐 Language Note
Please note that you will encounter mixed **French** and **German** terminology across the interface, codebase, and documentation (e.g., *Steckdosen, Heizung, Strom, Haushalt, Keller*). This reflects my personal environment as a French national residing in Germany!

### 🛡️ Security & Feedback
While this is a personal home architecture project, feel free to open an issue or share feedback if you spot any obvious security vulnerabilities, leaks, or unhidden confidential information that may have escaped the `.gitignore` rules or that would have escaped my attention.

### 📌 Why this project is not "Plug & Play" for other homes:
1. **Excluded Config Files & Data (`.gitignore`)**: For privacy and security reasons, all files containing IP addresses, API keys, MQTT credentials, hardware topologies (`config/app.conf`, `config/home_structure.conf`, `config/devices.json`), and private data (`data/tasks.json`) **are not synchronized** on GitHub.
2. **Specific Hardware Coupling**: The code relies on a precise heterogeneous hardware fleet (Tasmota plugs, Shelly Gen1/Gen2/Gen3, OpenBeken dimmers, MAX! EQ-3 thermostats via Cube, ITGW 433MHz RF gateway, Netatmo weather station, Android ADB gateways, Windows PCs with RPC/WOL).
3. **Rigid Logical Mapping**: Energy subtraction rules and room hierarchies are directly bound to the electrical schematics and physical network layout of the home.

---

## 🚀 Overview & Architecture

The A.S.H.E.S. architecture relies on **instantaneous refreshing with minimal latency (< 10ms)** without full page reloads. To preserve server SD cards / SSDs and avoid I/O bottlenecks, all real-time state management is handled in volatile RAM memory.

```
   ┌─────────────────────────────────────────────────────────┐
   │                 IoT Devices & Sensors                   │
   │  (Tasmota, Shelly, OpenBeken, MAX! Cube, Netatmo, ADB)  │
   └───────────────────────────┬─────────────────────────────┘
                               │ MQTT / HTTP / ADB / RF433
                               ▼
   ┌─────────────────────────────────────────────────────────┐
   │            Python Workers & Node.js Daemon              │
   │   (sha_cache_builder.py, maxcube_daemon.js, monitor)   │
   └───────────────────────────┬─────────────────────────────┘
                               │ Atomic Write
                               ▼
   ┌─────────────────────────────────────────────────────────┐
   │               Volatile RAM Cache (/dev/shm)             │
   │               (data/sha_live.json - Symlink)            │
   └───────────────────────────┬─────────────────────────────┘
                               │ Ultra-fast Read
                               ▼
   ┌─────────────────────────────────────────────────────────┐
   │              PHP 8.2 Web Interface / JS Core            │
   │      (Dashboard, Steckdose, Strom, Heizung, Task)       │
   └───────────────────────────┬─────────────────────────────┘
```

---

## ✨ Main Features

### 1. 🔌 Steckdosen & Lights (Device Control)
- **Multi-protocol**: Native support for Tasmota, Shelly Gen1/2/3 (RPC), OpenBeken, and virtual switches.
- **Dynamic Dimming**: Management of Dimmers (OpenBeken & Shelly Dimmer 2) with persistent state saving.
- **PC & Media Management**: Wake-On-LAN startup (via Docker UDP relay) and remote shutdown for Windows (net RPC) / Android (ADB keyevents).

### 2. ⚡ Strom (Energy Monitoring & Self-Consumption)
- **Real-Time Telemetry**: Instant readout of main meters (Shelly 3EM / Pro 3EM) and PV solar production.
- **Automated Calculations**:
  - **Coverage Rate** (%): Ratio of identified room/device consumption relative to the whole-house consumption.
  - **Solar Autarky** (%): Percentage of total consumption covered by PV solar production.
- **Smart Cross-Subtraction**: Automatic deduction of sub-device power draw from sub-meters to prevent double counting (e.g., Family PC power deducted from Network Rack block).

### 3. 🔥 Heizung (Climate & Thermal Subsystem)
- **MAX! EQ-3 Control**: Integrated decoupled Node.js daemon (`scripts/maxcube_daemon.js`) communicating asynchronously via JSON command tickets (`data/heiz_cmd.json`).
- **Setpoint Management**: Temperature adjustments, tempered BOOST mode, and automatic window-open detection.

### 4. 🧹 Haushalt (Task Manager & Cleanliness Score)
- **Dynamic Cleanliness Engine**: Room-by-room and global cleanliness score calculation (0% to 100%), calculated based on task effort level (1–5) and time elapsed against defined frequencies.
- **Daily Targets**: Automated daily selection cached in RAM (`data/daily_tasks_cache.json`), balancing quick wins and heavier chores.
- **Modern Modal UI**: Add/edit tasks seamlessly without reloads, including manual updates of last-accomplished timestamps (`datetime-local`).

### 5. 🪟 Rollläden & ☁️ Wetter / CO2
- **Roller Shutters**: 433MHz UDP frame transmission via ITGW gateway.
- **Netatmo Weather & Air Quality**: Automated indoor CO2 tracking (`Keller`), automatic air extractor fan regulation, and external matrix display feed (Wemos MAX7219).

### 6. 🔔 PWA & Native Push Notifications
- **iOS/Android PWA Support**: Installable standalone web app (`manifest.json`, `sw.js`), featuring iOS fullscreen mode handling.
- **WebPush (pywebpush)**: Native push alerts (appliance cycle completion for washer/dishwasher, critical thresholds).

---

## 📁 Project Structure

```
sha/
├── assets/
│   └── css/style.css        # Centralized styling (Ashes Theme / Charcoal & Orange)
├── core/
│   ├── functions.php        # Backend engine (RAM calculations, AJAX handlers)
│   └── functions.js         # Frontend engine (10s auto-refresh, Task modal, Event handlers)
├── config/
│   ├── app.conf             # Sensitive config (MQTT, Auth, API) [NOT SYNCHRONIZED]
│   └── home_structure.conf # Room hierarchy & device mapping [NOT SYNCHRONIZED]
├── data/
│   ├── sha_live.json        # Symlink to volatile RAM (/dev/shm) [NOT SYNCHRONIZED]
│   └── tasks.json           # Household tasks database [NOT SYNCHRONIZED]
├── scripts/
│   ├── sha_cache_builder.py # Python daemon listening to MQTT & pinging network
│   ├── sha_monitor.py       # Appliance cycle monitoring & Push alerting
│   ├── maxcube_daemon.js    # Node.js daemon controlling MAX! Cube
│   └── fix_rights.sh        # Dynamic permissions reset script
├── index.php                # Main dashboard
├── steckdose.php            # Plugs / Dimmers / PC control
├── strom.php                # Power consumption monitoring
├── heiz.php                 # Heating interface
├── task.php                 # Household task manager
└── INSTALL.md               # System setup guide & Systemd daemons
```

---
