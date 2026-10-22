-- Phase 3: history importer.
-- One row per upload; readings and review items point back at the batch so a bad import can be undone.

CREATE TABLE import_batches (
  id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  file            VARCHAR(255) NOT NULL,
  kind            ENUM('feeders','solar','water_energy','sql') NOT NULL,
  month           CHAR(7) NOT NULL,                       -- YYYY-MM chosen by the admin / CLI
  policy          VARCHAR(20) NOT NULL DEFAULT 'keep',    -- keep | replace-imported
  rows_added      INT UNSIGNED NOT NULL DEFAULT 0,
  rows_same       INT UNSIGNED NOT NULL DEFAULT 0,        -- already in the database with the same value
  rows_conflict   INT UNSIGNED NOT NULL DEFAULT 0,        -- already there with a different value, kept
  rows_replaced   INT UNSIGNED NOT NULL DEFAULT 0,
  issues          INT UNSIGNED NOT NULL DEFAULT 0,
  days_missing    VARCHAR(255) NULL,
  summary         MEDIUMTEXT NULL,                        -- JSON: sheets used/skipped and notes
  created_by      INT UNSIGNED NOT NULL,
  created_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  undone_at       DATETIME NULL,
  KEY idx_month (month),
  FOREIGN KEY (created_by) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE readings
  ADD COLUMN import_batch_id INT UNSIGNED NULL AFTER source,
  ADD KEY idx_import_batch (import_batch_id);

ALTER TABLE import_issues
  ADD COLUMN batch_id INT UNSIGNED NULL,
  ADD COLUMN kind ENUM('cell','suspect','info') NOT NULL DEFAULT 'cell',
  ADD COLUMN message VARCHAR(255) NULL,
  ADD KEY idx_issue_batch (batch_id, status);
