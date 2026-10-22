# Herahakatha — Build Spec (v1)

Powerhouse logsheet web app for Canareef: hourly feeder readings, half-hourly solar, daily meters (water, inverters, generators, diesel) and the generator set log. Replaces the Excel logsheets.

- **Plan document (the why):** https://claude.ai/code/artifact/cef249e3-0bd7-4c3f-9710-ca3e76a188c7
- **This file (the how):** everything needed to start building phase 1 in Laragon and deploy to Hostinger.

---

## 1. Ground rules

| Item | Decision |
|---|---|
| Language / runtime | PHP 8.2+ (`declare(strict_types=1);` in every file) |
| Database | MySQL 8 locally (Laragon). Hostinger's MySQL/MariaDB in production — so **no MySQL-8-only syntax** (stick to InnoDB, utf8mb4, standard SQL; CTEs and window functions are OK, they exist in MariaDB 10.2+) |
| Framework | None. Small hand-written MVC: front controller, router, PDO, PHP views |
| Packages (Composer) | `phpoffice/phpspreadsheet` (import + Excel export), `dompdf/dompdf` (PDF) |
| Front end | Bootstrap 5.3, Alpine.js 3, Chart.js 4 — **copied into `public/assets/vendor/`**, not loaded from a CDN (the offline queue needs local files) |
| One app, two views | Same URLs and pages for phone and PC; layout changes by screen width. No separate mobile app |
| Dev environment | Laragon on Windows → `http://herahakatha.test` |
| Production | Hostinger (hPanel), HTTPS |
| Timezone | `Indian/Maldives` (UTC+5). Store local `DATETIME`; set PHP default timezone and MySQL session `time_zone = '+05:00'` on every connection |

---

## 2. Domain rules (read before coding)

These come from the existing logsheets and the answers given on 15 Oct 2026.

### 2.1 How readings are taken
- **On each hour** an operator walks to the panels and reads each feeder's **instantaneous kW** at that moment, then enters it. Same for solar every 30 min (total plant kW from the **inverter display**).
- A reading is filed under its **slot** (`reading_at`, e.g. `2026-10-15 14:00:00`). The real save time is `entered_at`.
- A reading saved more than `late_minutes` (default 20) after its slot is kept but flagged **late**.

### 2.2 Log types

| Log | Meters | Slots | Unit | Type |
|---|---|---|---|---|
| Feeder Reading 1 | HV Network (incomer) + 11 feeders | Hourly 00:00–23:00 (24) | kW | interval |
| Feeder Reading 2 | 14 feeders | Hourly 00:00–23:00 (24) | kW | interval |
| Solar | Solar Plant 1 (more plants later) | Every 30 min 06:00–18:00 (25) | kW | interval |
| Daily meters | Water Line 1–11, Inverters 1–9, Generators 1–4, Diesel flow meter | Once per day | m³ / kWh / L | cumulative |
| Genset log | Generators 1–4 (only those running) | Hourly, log day runs **08:00 → 07:00 next day** | mixed | genset (own table) |

### 2.3 Calculations (the app computes all of these — never typed)
- **Feeder daily energy (estimate)** = Σ of the 24 hourly kW values × 1 h → kWh.
- **Solar daily energy (estimate)** = Σ of the 25 half-hourly kW values × 0.5 h → kWh. Cross-check against the sum of the inverter kWh counters for the day.
- **Cumulative consumption for day D** = (reading on D+1 − reading on D) × `multiplier`. (Same convention as the current Water & Energy sheet: 1 Aug consumption = 2 Aug reading − 1 Aug reading.)
- **Generator counters**: `multiplier = 0.1` (34,165,000 − 34,147,000 = 18,000 → 1,800 kWh).
- **Daily electricity (kWh)** = Σ consumption of Generators 1–4.
- **Daily solar (kWh)** = Σ consumption of **all active inverters** (the old sheet only summed Inverters 1–4 — confirmed formula mistake).
- **Diesel used (L)** = flow-meter consumption for the day.
- **Fuel efficiency (kWh/L)** = daily electricity ÷ diesel used. Monthly figure = Σ kWh ÷ Σ L (not the average of daily ratios).
- **Solar share %** = solar kWh ÷ (solar kWh + generator kWh).
- **Unmetered / losses (kW, per hour)** = HV Network − Σ all other feeders. Observed: 1 Sep 2026 00:00 → feeders 233.8 kW vs HV 266.9 kW (feeders = 88% of HV). *Assumption to confirm: all 25 feeders sit under HV Network.*
- **Water per day (m³)** = Σ consumption of active water lines; **m³/h** = ÷ 24.

### 2.4 Validation
Hard rules **block** the save. Soft rules return **warnings**; the user must tap "Save anyway" (optional note) and the warning codes are stored on the reading.

| Code | Rule | Applies to | Type |
|---|---|---|---|
| `NOT_NUMBER` | Must match `^\d+(\.\d+)?$` after trimming (rejects `11..21`, `23.8/4`, `7.72.`, blanks) | all | block |
| `NEGATIVE` | value < 0 | all | block |
| `FUTURE` | `reading_at` > now + 10 min | all | block |
| `OUT_OF_WINDOW` | Operator entering a day older than `operator_edit_days` (default 1 = today + yesterday) | operator role | block |
| `NOT_A_SLOT` | `reading_at` not on the meter's slot grid (e.g. 14:10 for an hourly meter) | interval | block |
| `COUNTER_BACKWARDS` | cumulative reading < previous reading | cumulative | block (supervisor/admin may override with reason "meter replaced/reset") |
| `MIN_MAX` | outside meter `min_value`/`max_value` | all | warn |
| `SPIKE` | value > `spike_factor` (3) × median of same slot over last `median_days` (14), or < median ÷ 3. Only when ≥ 5 samples and median ≥ `spike_floor` (feeders 1 kW, solar 20 kW) | interval | warn |
| `JUMP` | daily consumption differs > `daily_change_pct` (40%) from the 7-day average | cumulative | warn |
| `FEEDER_SUM` | Σ feeders vs HV Network outside ± `feeder_sum_tolerance_pct` (25%) — shown on the hour summary, not per field. Disabled by default until 2.3 assumption is confirmed | hour total | warn |
| `GENSET_LIMIT` | oil pressure, water temp, battery V, Hz, volts outside limits in `settings` (limits to be supplied by the chief engineer; skip check while empty) | genset | warn (red) |
| `LATE` | saved > `late_minutes` after slot | interval | flag only (no prompt) |

Zero is a valid reading (e.g. Heat Ex. Pump, Laundry Dryers at night).

---

## 3. Laragon setup

1. Install **Laragon Full** (Apache, MySQL, PHP 8.2+, Composer, HeidiSQL).
2. Menu → PHP → version 8.2 or newer. Menu → PHP → Extensions: make sure `pdo_mysql`, `mbstring`, `zip`, `gd`, `xml`/`dom`, `fileinfo`, `intl` are ticked.
3. Create the project folder: `C:\laragon\www\herahakatha`. Laragon's auto virtual host gives `http://herahakatha.test` (Menu → Apache → reload if it doesn't appear).
4. Inside the folder: `composer init` (or use the `composer.json` below) then `composer install`.
5. Create the database in HeidiSQL (or `mysql -u root`):
   ```sql
   CREATE DATABASE herahakatha CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
   ```
   Run `database/schema.sql`, then `database/seed.sql`.
6. Copy `config/env.example.php` → `config/env.php` and fill in DB credentials (`root` / empty password on Laragon by default).
7. Create the first admin: `php cli/create_admin.php admin "Shirhaan"` (prompts for a password, stores `password_hash`).
8. Open `http://herahakatha.test`. To test on a phone on the same Wi-Fi, use the PC's LAN IP (Laragon → Menu → Apache → allow LAN), or test the phone layout with browser dev tools.

`composer.json`:
```json
{
  "name": "canareef/herahakatha",
  "type": "project",
  "require": {
    "php": ">=8.2",
    "phpoffice/phpspreadsheet": "^2.0",
    "dompdf/dompdf": "^3.0"
  },
  "autoload": { "psr-4": { "App\\": "app/" } }
}
```

---

## 4. Folder structure

```
herahakatha/
├─ .htaccess                 # rewrites everything into public/ (works on Laragon and Hostinger)
├─ composer.json
├─ app/
│  ├─ Core/                  # Router, DB (PDO), Auth, Csrf, View, Request, Response, helpers.php (e(), url(), old())
│  ├─ Controllers/
│  │  ├─ AuthController.php
│  │  ├─ TodayController.php
│  │  ├─ EntryController.php          # feeder/solar/daily/genset pages
│  │  ├─ Api/ReadingsController.php   # JSON: slots, save, batch
│  │  ├─ Api/GensetController.php
│  │  ├─ Api/TodayController.php
│  │  ├─ Api/ChartController.php
│  │  ├─ ReportController.php
│  │  ├─ AnalysisController.php
│  │  └─ Admin/ (Meters, Users, Settings, Import, Audit)
│  ├─ Models/                # Meter, Reading, GensetLog, DailyTotal, User, Setting, Audit, ImportIssue
│  └─ Services/
│     ├─ ReadingValidator.php   # all rules in 2.4, returns ['errors'=>[], 'warnings'=>[]]
│     ├─ Totals.php             # refreshes daily_totals (2.3)
│     ├─ Slots.php              # slot grid per meter, "due now", missing slots
│     ├─ ReportBuilder.php
│     ├─ Exporter.php           # Excel (PhpSpreadsheet), PDF (Dompdf)
│     └─ ExcelImporter.php      # phase 3
├─ views/
│  ├─ layout.php             # top bar + bottom nav (phone) / sidebar (PC)
│  ├─ today.php
│  ├─ entry/ feeders.php, solar.php, daily.php, genset.php
│  ├─ reports/ …
│  ├─ analysis/ …
│  └─ admin/ …
├─ public/
│  ├─ index.php              # front controller
│  ├─ .htaccess              # route all non-files to index.php
│  ├─ manifest.webmanifest
│  ├─ sw.js                  # service worker (app shell cache)
│  └─ assets/ css/app.css, js/app.js, js/queue.js, vendor/(bootstrap, alpine, chartjs), icons/
├─ config/  config.php, env.example.php, env.php (not committed), .htaccess (deny)
├─ database/ schema.sql, seed.sql, migrations/001_….sql
├─ storage/ logs/, imports/, backups/, .htaccess (deny)
└─ cli/  create_admin.php, nightly.php, refresh_totals.php, backup.php, import.php
```

Root `.htaccess`:
```apache
RewriteEngine On
RewriteRule ^(app|config|database|storage|cli|vendor|views)(/|$) - [F,L]
RewriteCond %{REQUEST_URI} !^/public/
RewriteRule ^(.*)$ public/$1 [L]
```

`public/.htaccess`:
```apache
RewriteEngine On
RewriteCond %{REQUEST_FILENAME} !-f
RewriteCond %{REQUEST_FILENAME} !-d
RewriteRule ^ index.php [QSA,L]
```

`app/`, `config/`, `storage/`, `cli/`, `database/`, `views/` each also get a one-line `.htaccess`: `Require all denied`.

---

## 5. Database

### 5.1 `database/schema.sql`

```sql
SET NAMES utf8mb4;

CREATE TABLE sites (
  id        INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  name      VARCHAR(100) NOT NULL,
  timezone  VARCHAR(40)  NOT NULL DEFAULT 'Indian/Maldives'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE users (
  id             INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  site_id        INT UNSIGNED NOT NULL,
  name           VARCHAR(100) NOT NULL,
  username       VARCHAR(50)  NOT NULL UNIQUE,
  password_hash  VARCHAR(255) NOT NULL,
  role           ENUM('operator','supervisor','viewer','admin') NOT NULL DEFAULT 'operator',
  active         TINYINT(1) NOT NULL DEFAULT 1,
  last_login_at  DATETIME NULL,
  created_at     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (site_id) REFERENCES sites(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE meter_groups (
  id          INT UNSIGNED PRIMARY KEY,
  site_id     INT UNSIGNED NOT NULL,
  name        VARCHAR(60) NOT NULL,
  entry_type  ENUM('interval','daily','genset') NOT NULL,
  sort_order  SMALLINT NOT NULL DEFAULT 0,
  FOREIGN KEY (site_id) REFERENCES sites(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE meters (
  id            INT UNSIGNED PRIMARY KEY,           -- explicit ids (6–31 match the old meter_readings export)
  site_id       INT UNSIGNED NOT NULL,
  group_id      INT UNSIGNED NOT NULL,
  parent_id     INT UNSIGNED NULL,                  -- inverter -> its solar plant
  code          VARCHAR(40)  NOT NULL,
  name          VARCHAR(100) NOT NULL,
  kind          ENUM('feeder','solar','water','inverter','generator','fuel') NOT NULL,
  reading_type  ENUM('interval','cumulative') NOT NULL,
  unit          VARCHAR(10)  NOT NULL,
  multiplier    DECIMAL(10,4) NOT NULL DEFAULT 1,
  interval_min  SMALLINT NULL,                      -- 60 feeders, 30 solar, NULL daily
  window_start  TIME NULL,
  window_end    TIME NULL,
  min_value     DECIMAL(14,3) NULL,
  max_value     DECIMAL(14,3) NULL,
  is_incomer    TINYINT(1) NOT NULL DEFAULT 0,
  sort_order    SMALLINT NOT NULL DEFAULT 0,       -- walk order on the entry screen
  active        TINYINT(1) NOT NULL DEFAULT 1,
  UNIQUE KEY uq_site_code (site_id, code),
  KEY idx_group (group_id, sort_order),
  FOREIGN KEY (site_id)  REFERENCES sites(id),
  FOREIGN KEY (group_id) REFERENCES meter_groups(id),
  FOREIGN KEY (parent_id) REFERENCES meters(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE readings (
  id             BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  meter_id       INT UNSIGNED NOT NULL,
  reading_at     DATETIME NOT NULL,                 -- slot (hourly / half-hourly) or day 00:00 for daily meters
  value          DECIMAL(14,3) NOT NULL,
  entered_by     INT UNSIGNED NOT NULL,
  entered_at     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_by     INT UNSIGNED NULL,
  updated_at     DATETIME NULL,
  is_late        TINYINT(1) NOT NULL DEFAULT 0,
  warning_codes  VARCHAR(255) NULL,                 -- e.g. 'SPIKE,MIN_MAX' when saved anyway
  note           VARCHAR(255) NULL,
  source         ENUM('app','import') NOT NULL DEFAULT 'app',
  client_uuid    CHAR(36) NULL,                     -- idempotency key from the offline queue
  UNIQUE KEY uq_meter_time (meter_id, reading_at),
  UNIQUE KEY uq_client_uuid (client_uuid),
  KEY idx_time (reading_at),
  FOREIGN KEY (meter_id)   REFERENCES meters(id),
  FOREIGN KEY (entered_by) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE reading_audit (
  id          BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  reading_id  BIGINT UNSIGNED NOT NULL,
  old_value   DECIMAL(14,3) NOT NULL,
  new_value   DECIMAL(14,3) NOT NULL,
  changed_by  INT UNSIGNED NOT NULL,
  changed_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  reason      VARCHAR(255) NULL,
  KEY idx_reading (reading_id),
  FOREIGN KEY (reading_id) REFERENCES readings(id),
  FOREIGN KEY (changed_by) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE genset_logs (
  id           BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  meter_id     INT UNSIGNED NOT NULL,               -- generator meter (80–83)
  log_at       DATETIME NOT NULL,                   -- hourly slot
  volts        DECIMAL(7,2) NULL,
  hz           DECIMAL(5,2) NULL,
  amp_r        DECIMAL(8,2) NULL,
  amp_y        DECIMAL(8,2) NULL,
  amp_b        DECIMAL(8,2) NULL,
  pf           DECIMAL(4,3) NULL,
  kw           DECIMAL(9,2) NULL,
  oil_bar      DECIMAL(5,2) NULL,
  water_c      DECIMAL(5,1) NULL,
  kwh          DECIMAL(14,1) NULL,
  run_hours    DECIMAL(10,1) NULL,
  battery_v    DECIMAL(5,2) NULL,
  rpm          SMALLINT UNSIGNED NULL,
  visual_ok    TINYINT(1) NULL,
  visual_note  VARCHAR(255) NULL,
  solar_kw     DECIMAL(9,2) NULL,
  warning_codes VARCHAR(255) NULL,
  entered_by   INT UNSIGNED NOT NULL,
  entered_at   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  client_uuid  CHAR(36) NULL,
  UNIQUE KEY uq_genset_time (meter_id, log_at),
  UNIQUE KEY uq_genset_uuid (client_uuid),
  FOREIGN KEY (meter_id)   REFERENCES meters(id),
  FOREIGN KEY (entered_by) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE daily_totals (
  meter_id        INT UNSIGNED NOT NULL,
  day             DATE NOT NULL,
  total           DECIMAL(16,3) NULL,               -- interval: sum of values
  energy          DECIMAL(16,3) NULL,               -- interval: total x interval hours (kWh)
  consumption     DECIMAL(16,3) NULL,               -- cumulative: (next day - day) x multiplier
  avg_value       DECIMAL(14,3) NULL,
  peak_value      DECIMAL(14,3) NULL,
  peak_at         DATETIME NULL,
  slots_filled    SMALLINT NOT NULL DEFAULT 0,
  slots_expected  SMALLINT NOT NULL DEFAULT 0,
  refreshed_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (meter_id, day),
  FOREIGN KEY (meter_id) REFERENCES meters(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE day_status (
  site_id      INT UNSIGNED NOT NULL,
  day          DATE NOT NULL,
  group_id     INT UNSIGNED NOT NULL,
  approved_by  INT UNSIGNED NOT NULL,
  approved_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (site_id, day, group_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE settings (
  `key`    VARCHAR(60) PRIMARY KEY,
  `value`  VARCHAR(255) NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE login_attempts (
  id            BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  username      VARCHAR(50) NOT NULL,
  ip            VARCHAR(45) NOT NULL,
  success       TINYINT(1) NOT NULL,
  attempted_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_user_time (username, attempted_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- phase 3 (importer)
CREATE TABLE import_issues (
  id               BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  file             VARCHAR(255) NOT NULL,
  sheet            VARCHAR(60)  NOT NULL,
  cell             VARCHAR(10)  NOT NULL,
  raw_value        VARCHAR(100) NULL,
  meter_id         INT UNSIGNED NULL,
  reading_at       DATETIME NULL,
  suggested_value  DECIMAL(14,3) NULL,
  status           ENUM('open','accepted','fixed','ignored') NOT NULL DEFAULT 'open',
  resolved_by      INT UNSIGNED NULL,
  resolved_at      DATETIME NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
```

### 5.2 `database/seed.sql`

Meter IDs 6–31 match `panel_id` in the old `meter_readings_bulk1/2.sql` export (verified against the July 2025 feeder files).

```sql
INSERT INTO sites (id, name) VALUES (1, 'Canareef Resort Maldives');

INSERT INTO meter_groups (id, site_id, name, entry_type, sort_order) VALUES
 (1, 1, 'Feeder Reading 1', 'interval', 1),
 (2, 1, 'Feeder Reading 2', 'interval', 2),
 (3, 1, 'Solar',            'interval', 3),
 (4, 1, 'Solar inverters',  'daily',    4),
 (5, 1, 'Water',            'daily',    5),
 (6, 1, 'Generators',       'daily',    6),
 (7, 1, 'Fuel',             'daily',    7);

-- Feeder Reading 1 (hourly kW)
INSERT INTO meters (id, site_id, group_id, code, name, kind, reading_type, unit, interval_min, window_start, window_end, is_incomer, sort_order) VALUES
 ( 6,1,1,'HV-NETWORK','HV Network','feeder','interval','kW',60,'00:00','23:00',1, 1),
 ( 7,1,1,'JSB-9001','JSB-9001','feeder','interval','kW',60,'00:00','23:00',0, 2),
 ( 8,1,1,'JSB-8001','JSB-8001','feeder','interval','kW',60,'00:00','23:00',0, 3),
 ( 9,1,1,'JSB-9002','JSB-9002','feeder','interval','kW',60,'00:00','23:00',0, 4),
 (10,1,1,'JSB-8002','JSB-8002','feeder','interval','kW',60,'00:00','23:00',0, 5),
 (11,1,1,'EX-STAFF-BLK','Ex. Staff Blk','feeder','interval','kW',60,'00:00','23:00',0, 6),
 (12,1,1,'S-STAFF-BLK','S. Staff Blk','feeder','interval','kW',60,'00:00','23:00',0, 7),
 (13,1,1,'MDB-116-117','MDB 116-117 BV 169-189','feeder','interval','kW',60,'00:00','23:00',0, 8),
 (14,1,1,'MDB-118-119','MDB 118-119 BV 272-290','feeder','interval','kW',60,'00:00','23:00',0, 9),
 (15,1,1,'KATHEEB-VILLA','Katheeb Villa','feeder','interval','kW',60,'00:00','23:00',0,10),
 (16,1,1,'STP-ENG-OFFICE','STP Eng Office','feeder','interval','kW',60,'00:00','23:00',0,11),
 (17,1,1,'LAUNDRY-IRONS','Laundry Irons','feeder','interval','kW',60,'00:00','23:00',0,12);

-- Feeder Reading 2 (hourly kW)
INSERT INTO meters (id, site_id, group_id, code, name, kind, reading_type, unit, interval_min, window_start, window_end, sort_order) VALUES
 (18,1,2,'MAIN-STORE','Main Store','feeder','interval','kW',60,'00:00','23:00', 1),
 (19,1,2,'MAIN-KITCHEN','Main Kitchen','feeder','interval','kW',60,'00:00','23:00', 2),
 (20,1,2,'SECURITY-HUT','Security Hut','feeder','interval','kW',60,'00:00','23:00', 3),
 (21,1,2,'HEAT-EX-PUMP','Heat Ex. Pump','feeder','interval','kW',60,'00:00','23:00', 4),
 (22,1,2,'MDB-120-121','MDB 120-121 BV 150-167','feeder','interval','kW',60,'00:00','23:00', 5),
 (23,1,2,'IT-BLDG','IT Bldg','feeder','interval','kW',60,'00:00','23:00', 6),
 (24,1,2,'HR-MOSQUE','HR / Mosque','feeder','interval','kW',60,'00:00','23:00', 7),
 (25,1,2,'POWER-HOUSE','Power House','feeder','interval','kW',60,'00:00','23:00', 8),
 (26,1,2,'LAUNDRY-MACHINES','Laundry Machines','feeder','interval','kW',60,'00:00','23:00', 9),
 (27,1,2,'LAUNDRY-DRYERS','Laundry Dryers','feeder','interval','kW',60,'00:00','23:00',10),
 (28,1,2,'WATER-PLANT','Water Plant','feeder','interval','kW',60,'00:00','23:00',11),
 (29,1,2,'INCINERATOR-WS','Incinerator Work Shop','feeder','interval','kW',60,'00:00','23:00',12),
 (30,1,2,'PUMP-ROOM','Pump Room','feeder','interval','kW',60,'00:00','23:00',13),
 (31,1,2,'CARPENTRY','Carpentry','feeder','interval','kW',60,'00:00','23:00',14);

-- Solar plant (half-hourly kW from the inverter display). Add plant 2 later as id 41+ etc.
INSERT INTO meters (id, site_id, group_id, code, name, kind, reading_type, unit, interval_min, window_start, window_end, sort_order) VALUES
 (40,1,3,'SOLAR-PLANT-1','Solar Plant 1','solar','interval','kW',30,'06:00','18:00',1);

-- Solar inverters (daily kWh counters), linked to plant 1
INSERT INTO meters (id, site_id, group_id, parent_id, code, name, kind, reading_type, unit, sort_order) VALUES
 (51,1,4,40,'INV-1','Inverter 1','inverter','cumulative','kWh',1),
 (52,1,4,40,'INV-2','Inverter 2','inverter','cumulative','kWh',2),
 (53,1,4,40,'INV-3','Inverter 3','inverter','cumulative','kWh',3),
 (54,1,4,40,'INV-4','Inverter 4','inverter','cumulative','kWh',4),
 (55,1,4,40,'INV-5','Inverter 5','inverter','cumulative','kWh',5),
 (56,1,4,40,'INV-6','Inverter 6','inverter','cumulative','kWh',6),
 (57,1,4,40,'INV-7','Inverter 7','inverter','cumulative','kWh',7),
 (58,1,4,40,'INV-8','Inverter 8','inverter','cumulative','kWh',8),
 (59,1,4,40,'INV-9','Inverter 9','inverter','cumulative','kWh',9);

-- Water lines (daily m3). Only Line 1 active today.
INSERT INTO meters (id, site_id, group_id, code, name, kind, reading_type, unit, sort_order, active) VALUES
 (60,1,5,'WATER-L1','Line 1 Main','water','cumulative','m3', 1,1),
 (61,1,5,'WATER-L2','Line 2','water','cumulative','m3', 2,0),
 (62,1,5,'WATER-L3','Line 3','water','cumulative','m3', 3,0),
 (63,1,5,'WATER-L4','Line 4','water','cumulative','m3', 4,0),
 (64,1,5,'WATER-L5','Line 5','water','cumulative','m3', 5,0),
 (65,1,5,'WATER-L6','Line 6','water','cumulative','m3', 6,0),
 (66,1,5,'WATER-L7','Line 7','water','cumulative','m3', 7,0),
 (67,1,5,'WATER-L8','Line 8','water','cumulative','m3', 8,0),
 (68,1,5,'WATER-L9','Line 9','water','cumulative','m3', 9,0),
 (69,1,5,'WATER-L10','Line 10','water','cumulative','m3',10,0),
 (70,1,5,'WATER-L11','Line 11','water','cumulative','m3',11,0);

-- Generators (daily kWh counter, display x 0.1 = kWh)
INSERT INTO meters (id, site_id, group_id, code, name, kind, reading_type, unit, multiplier, sort_order) VALUES
 (80,1,6,'GEN-1','Generator 1','generator','cumulative','kWh',0.1,1),
 (81,1,6,'GEN-2','Generator 2','generator','cumulative','kWh',0.1,2),
 (82,1,6,'GEN-3','Generator 3','generator','cumulative','kWh',0.1,3),
 (83,1,6,'GEN-4','Generator 4','generator','cumulative','kWh',0.1,4);

-- Diesel
INSERT INTO meters (id, site_id, group_id, code, name, kind, reading_type, unit, sort_order, active) VALUES
 (90,1,7,'DIESEL-PH','Powerhouse diesel flow meter','fuel','cumulative','L',1,1),
 (91,1,7,'DIESEL-OTHER','Other diesel','fuel','cumulative','L',2,0);

INSERT INTO settings (`key`, `value`) VALUES
 ('timezone','Indian/Maldives'),
 ('late_minutes','20'),
 ('operator_edit_days','1'),
 ('median_days','14'),
 ('spike_factor','3'),
 ('spike_floor_feeder','1'),
 ('spike_floor_solar','20'),
 ('daily_change_pct','40'),
 ('feeder_sum_check','0'),
 ('feeder_sum_tolerance_pct','25'),
 ('genset_day_start','08:00'),
 ('genset_oil_bar_min',NULL),
 ('genset_water_c_max',NULL),
 ('genset_battery_v_min',NULL),
 ('genset_hz_min',NULL),
 ('genset_hz_max',NULL),
 ('genset_volts_min',NULL),
 ('genset_volts_max',NULL),
 ('alert_email',NULL);
```

### 5.3 Rules for `readings` writes (in `Reading::save()`)
1. Run `ReadingValidator`. Errors → reject. Warnings without `force=true` → return warnings, don't save.
2. If `client_uuid` already exists → return the existing row (idempotent; the offline queue may resend).
3. If `(meter_id, reading_at)` exists and value differs → **update** + insert `reading_audit` row (old, new, user, reason). Operators may only update within their edit window.
4. Set `is_late`, `warning_codes`.
5. Call `Totals::refresh(meter_id, day)`. For cumulative meters also refresh `day − 1` (its consumption depends on this reading).

All of this runs inside one transaction.

---

## 6. Roles and permissions

| Action | operator | supervisor | viewer | admin |
|---|:-:|:-:|:-:|:-:|
| Enter readings (today, yesterday) | ✓ | ✓ | | ✓ |
| Edit older readings (reason required) | | ✓ | | ✓ |
| Override `COUNTER_BACKWARDS` | | ✓ | | ✓ |
| Approve a day | | ✓ | | ✓ |
| Reports, analysis, exports | own today only | ✓ | ✓ | ✓ |
| Meters, users, settings, import, audit | | | | ✓ |

Check role in the router (route → allowed roles) **and** again in the controller/service.

---

## 7. Routes

### Pages (server-rendered)
| Method | Path | Page |
|---|---|---|
| GET/POST | `/login`, `/logout` | Login |
| GET | `/` | Today |
| GET | `/entry/feeders/{group}` | Feeder entry (group 1 or 2), `?date=&hour=` |
| GET | `/entry/solar` | Solar entry, `?date=&slot=` |
| GET | `/entry/daily` | Daily meters, `?date=` |
| GET | `/entry/genset` | Genset log, `?meter=&date=` |
| GET | `/reports/feeders/daily` · `/reports/solar/daily` · `/reports/monthly` · `/reports/feeders/ranking` · `/reports/genset` · `/reports/completeness` | Reports (`?date=` / `?month=YYYY-MM`, `&format=pdf|xlsx`) |
| GET | `/analysis` · `/analysis/trends` · `/analysis/load-profile` · `/analysis/losses` · `/analysis/solar` · `/analysis/fuel` | Phase 4 |
| GET/POST | `/admin/meters` · `/admin/users` · `/admin/settings` · `/admin/audit` · `/admin/import` | Admin |

### JSON API (session cookie + `X-CSRF-Token` header)
| Method | Path | Body / query | Returns |
|---|---|---|---|
| GET | `/api/today` | — | progress per log (see 8.1) |
| GET | `/api/slots` | `group_id, date, time` | meters in walk order with `value`, `prev_value` (previous slot), `median`, `entered_by`, `is_late` |
| POST | `/api/readings` | `{meter_id, reading_at, value, note?, force?, reason?, client_uuid}` | `{status:'saved'|'warning'|'error', errors[], warnings[], reading}` |
| POST | `/api/readings/batch` | `{items:[…same shape…]}` | per-item results (used by the offline queue) |
| POST | `/api/genset-logs` | `{meter_id, log_at, …fields, force?, client_uuid}` | same shape as readings |
| GET | `/api/chart` | `meter_ids, from, to, bucket=raw|hour|day|month` | series for Chart.js |
| POST | `/api/day-status` | `{date, group_id}` | approve a day (supervisor) |

Values arrive as **strings** from the form; validate the string with the `NOT_NUMBER` regex before casting.

---

## 8. Screens

General layout: phone (< 768 px) → top bar with page title + date, **bottom nav**: Today · Enter · Reports · More. PC → left sidebar with the same items. Big touch targets (min 48 px), `inputmode="decimal"` on every number field, `enterkeyhint="next"`.

### 8.1 Today
One card per log, ordered by what is due:
- **Feeders 1 / Feeders 2** — "14:00 due · 13 of 24 hours complete". An hour counts complete when every active meter in the group has a value.
- **Solar** — "14:30 due · 17 of 25 slots".
- **Daily meters** — "Not done" / "Done 08:12 by Ahmed".
- **Genset log** — one line per generator with readings today.
- **Waiting to send: N** (from the offline queue) — shown only when N > 0.
Missed past slots in amber; tapping a card opens the entry screen at the due slot.

### 8.2 Feeder entry (`/entry/feeders/1|2`)
**Phone (the hourly round):**
- Opens on the due hour. Header: `Feeders 1 · 15 Oct · 14:00` with ◀ ▶ to change hour.
- List of meters in `sort_order`. Each row: name, big number input, greyed "last hr 13.4", status icon (✓ saved / ⚠ warning / ⏳ queued).
- Value **saves on blur / Next** (not on a final Save button). Cursor moves to the next feeder.
- Warning → row turns amber with the message and two buttons: **Fix** / **Save anyway** (+ optional note).
- Footer after the last feeder: hour total kW, and (if enabled) HV vs Σ feeders.

**PC:** same route renders a grid — rows = 24 hours, columns = meters. Arrow keys move; Enter saves the cell; paste a block from Excel (parse tab/newline, validate each cell, show results per cell). Column footers: daily total kW and estimated kWh.

### 8.3 Solar entry
Same pattern, one field per active solar plant per 30-min slot, plus a small Chart.js line of today's values that redraws on each save.

### 8.4 Daily meters
One form per date (default today): sections Water (active lines), Solar inverters, Generators, Fuel. Each row: name, input, yesterday's reading, **live consumption** (`(value − yesterday) × multiplier`) as you type. Hidden: inactive meters.

### 8.5 Genset log
Choose generator (only those with recent readings or marked running) and date (log day 08:00–07:00). One card per hour with 15 fields; visual check = OK / Not OK toggle + note. Out-of-limit values shown red.

### 8.6 Admin → Meters
List by group; add/edit meter (all `meters` columns), drag or number to set walk order, deactivate (never delete a meter that has readings). Adding **new solar**: create a `solar` meter (half-hourly plant) and its `inverter` meters with `parent_id` = the plant.

---

## 9. Offline queue (phase 1)

Entry depends on the resort's internet (Hostinger). Rounds must not stop during an outage.

- `public/sw.js` caches the app shell (layout CSS/JS, vendor files, entry pages' HTML templates). API calls are **not** cached.
- `public/assets/js/queue.js`: when `fetch('/api/readings')` fails (network error or `navigator.onLine === false`), store the item in **IndexedDB** store `queue` with its `client_uuid` (use `crypto.randomUUID()`), mark the row ⏳.
- Flush the queue on `online` event, on page load, and every 60 s → `POST /api/readings/batch`. Remove items that come back `saved`; items that come back `warning` stay and are shown on the Today screen for the user to resolve.
- The server is idempotent on `client_uuid`, so resending is safe.
- If the session expired while offline, the flush gets 401 → show a login prompt, keep the queue, flush after login.

---

## 10. Reports (phase 2)

| Report | Content | Formats |
|---|---|---|
| Daily feeder | 24 h × feeders (both groups), column totals, estimated kWh, peak hour per feeder, HV vs Σ feeders per hour | screen, PDF, xlsx |
| Daily solar | half-hourly table + line chart per plant, estimated kWh, inverter-counter kWh, difference % | screen, PDF |
| Monthly Water & Energy statistics | per day: water m³, generator kWh, solar kWh, diesel L, kWh/L, solar share %. Footer: totals and daily averages (monthly kWh/L = Σ kWh ÷ Σ L) | screen, PDF, xlsx |
| Feeder ranking | kWh per feeder per day/month, share of total, change vs previous month | screen, xlsx, csv |
| Genset | per generator: running hours, kWh, avg kW, avg PF, out-of-limit events | screen, PDF |
| Completeness | per log per day: slots filled / expected, late count, warnings saved anyway, who entered | screen |

Reports read from `daily_totals` where possible; hourly tables read `readings`.

---

## 11. Security checklist
- `password_hash()` / `password_verify()`; session cookie `HttpOnly`, `Secure` (prod), `SameSite=Lax`; regenerate session id on login.
- Lock a username for 15 min after 5 failed attempts (`login_attempts`).
- CSRF token on every POST (form field or `X-CSRF-Token` header).
- PDO with prepared statements only; `PDO::ATTR_EMULATE_PREPARES = false`.
- Escape all output with `e()` (`htmlspecialchars(..., ENT_QUOTES, 'UTF-8')`).
- Role check on every route and in services.
- Force HTTPS on Hostinger (`.htaccess` redirect or hPanel setting).
- `config/env.php`, `storage/`, `vendor/` never web-reachable.

---

## 12. Deploying to Hostinger

1. hPanel → Websites → choose the domain/subdomain (e.g. `logs.<domain>`).
2. hPanel → Advanced → PHP Configuration → PHP 8.2+; enable the extensions in section 3.
3. hPanel → Databases → create MySQL database + user. Open phpMyAdmin → import `schema.sql`, then `seed.sql`.
4. Upload the project into the site's `public_html` (File Manager, SFTP, or hPanel Git). The root `.htaccess` sends traffic into `public/` and blocks the other folders.
5. Upload `vendor/` too if the plan has no SSH; otherwise `composer install --no-dev` over SSH.
6. Create `config/env.php` on the server with the Hostinger DB host/name/user/password and `APP_ENV=production`.
7. SSL on + force HTTPS.
8. hPanel → Advanced → Cron Jobs (use the PHP path hPanel shows):
   - nightly 01:30 → `php …/cli/nightly.php` (refresh totals for yesterday, missing/late check, alert email, backup dump to `storage/backups/`, delete dumps > 30 days).
9. Once a week download the latest dump to the resort.

---

## 13. History import (phase 3, summary)

Admin upload page (`/admin/import`), one month at a time:
- Date = folder month + first two digits of the sheet name. **Ignore** the date cell in C2 (often wrong).
- Map columns by header text (row 3) to `meters.name`/`code`, not by position.
- Solar: read only the sheet whose name contains `Final`.
- Water & Energy: import raw meter readings + diesel only; recompute everything.
- Non-numeric cells → `import_issues` (file, sheet, cell, raw text, suggested value) for review. Never guess silently.
- Skip `~$*.xlsx`, `Thumbs.db`, and duplicate folders (`2025/June/May` = `2025/May`).
- Old `meter_readings_bulk*.sql`: run in phpMyAdmin after `RENAME`/mapping `panel_id → meter_id` (ids are identical), `reading_time → reading_at`, `source='import'`.
- Readings stored with `source='import'`, `entered_by` = the admin running the import.

---

## 14. Phase 1 build checklist

- [ ] Laragon project, Composer, folder skeleton, both `.htaccess` files
- [ ] `Core/`: Router (method + path + role), DB (PDO singleton, timezone), View (layout + partials), Csrf, Auth, helpers
- [ ] `schema.sql` + `seed.sql` loaded; `cli/create_admin.php`
- [ ] Login / logout, lockout, role middleware
- [ ] Layout: bottom nav on phone, sidebar on PC (Bootstrap breakpoints)
- [ ] `Services/Slots.php`: slot grid per meter, due slot, missing slots per day
- [ ] `Services/ReadingValidator.php`: every rule in 2.4, unit-tested with the real bad values (`11..21`, `23.8/4`, `7.72.`, `3.54` among ~400s)
- [ ] `Services/Totals.php`: refresh `daily_totals` for interval and cumulative meters (2.3)
- [ ] `/api/slots`, `/api/readings`, `/api/readings/batch`, `/api/today`
- [ ] Feeder entry — phone list + PC grid (incl. paste)
- [ ] Solar entry with live chart
- [ ] Daily meters with live consumption
- [ ] Genset log + `/api/genset-logs`
- [ ] Today screen
- [ ] Offline queue (`sw.js`, `queue.js`, IndexedDB) — test by switching the phone to airplane mode mid-round
- [ ] Admin: meters, users, settings, audit log
- [ ] Deploy to Hostinger, nightly cron, HTTPS

**Phase 1 is done when** operators enter a full week of feeders, solar, daily meters and genset log in the app with no Excel, and the week's daily kWh, solar kWh, water m³ and kWh/L match a hand check.

---

## 15. Coding conventions
- `declare(strict_types=1);`, PSR-12 style, namespaces under `App\`.
- Controllers stay thin: validate input → call a service/model → render view or JSON.
- No SQL in views. No business rules in JavaScript that the server doesn't also enforce.
- All money-free numbers as `DECIMAL` in MySQL; format in views with `number_format`.
- Dates: always `DateTimeImmutable` with the site timezone; slot math in `Slots.php` only.
- Migrations: new file per change in `database/migrations/NNN_description.sql`; never edit an applied one.
- Log errors to `storage/logs/app-YYYY-MM-DD.log`; show users a friendly message.

## 16. Open items (do not block phase 1)
- Number of inverters in the new solar, and whether it has its own display for half-hourly kW.
- Time of day the diesel flow meter is read; confirm it shows cumulative litres.
- Genset alarm limits (oil bar, water °C, battery V, Hz, volts) from the chief engineer.
- Confirm all 25 feeders sit under HV Network (enables `FEEDER_SUM` and the losses view).
- Hostinger plan (SSH, backup frequency) and the domain/subdomain.
- First operator and supervisor accounts.
