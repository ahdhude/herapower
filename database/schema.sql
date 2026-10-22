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
