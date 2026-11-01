# Herahakatha

Powerhouse logsheet web app for Canareef. Replaces the Excel logsheets: hourly feeder readings, half-hourly solar,
daily meters (water, inverters, generators, diesel) and the generator set log. One app for phone and PC.

- Build spec: [`herahakatha-build-spec.md`](herahakatha-build-spec.md) · Plan: `Herahakatha - Logsheet Web App Plan.pdf`
- Stack: PHP 8.2+, MySQL/MariaDB, no framework (small hand-written MVC), Bootstrap 5.3 + Alpine.js 3 + Chart.js 4 (all vendored in `public/assets/vendor`, no CDN).

## Status

**Phases 1 and 3 are built** (data entry, and the Excel history importer), plus the on-screen reports from phase 2.

| Area | State |
|---|---|
| Login, roles (operator / supervisor / viewer / admin), lockout after 5 failures, CSRF | done |
| Today screen: progress per log, next-up prompt, supervisor day approval | done |
| Feeder entry: phone list (one hour at a time) and PC grid (24 h × feeders, keyboard, Excel paste) | done |
| Solar entry with live chart, daily meters with live consumption, genset log | done |
| Validation: every rule in spec 2.4 (hard blocks, soft warnings with "Save anyway", late flag) | done, 59 checks in `tests/run.php` |
| Offline queue (service worker + IndexedDB, idempotent on `client_uuid`) | done |
| Admin: meters, users, settings, audit log | done |
| Reports: daily feeder, daily solar, monthly Water & Energy, feeder ranking, genset, completeness (screen, CSV, print; Excel/PDF when the `zip` extension is on) | done |
| Excel history import (phase 3): feeder, solar and Water & Energy workbooks and the old SQL export, preview, review queue, undo | done, 74 checks in `tests/import_test.php`; all 55 real workbooks parse |
| Analysis screens (phase 4) | not started |

## Run it in Laragon

1. Folder: `C:\laragon\www\herapower` → Laragon's auto virtual host gives `http://herapower.test` (Menu → Apache → Reload if it doesn't appear).
2. PHP 8.2+ with `pdo_mysql, mbstring, zip, gd, xml, fileinfo, intl` ticked (Menu → PHP → Extensions). `zip` is needed for Excel export.
3. `composer install` (already done here; `vendor/` is git-ignored).
4. `config/env.php` is copied from `config/env.example.php` (database `herapower`, `root`, empty password).
5. Create the database and load schema + seed (this also applies `database/migrations/*.sql`):
   ```
   php cli/setup_db.php
   ```
   Later changes ship as new files in `database/migrations/`; apply them with `php cli/migrate.php` (each runs once).
6. Create the first admin (prompts for a password, or set `HH_PASSWORD`):
   ```
   php cli/create_admin.php admin "Shirhaan"
   ```
7. Optional, local only: two weeks of demo readings and four demo logins (written to `storage/demo-credentials.txt`):
   ```
   php cli/demo_data.php
   ```

**Which address?** Both work with Laragon's own Apache, no separate server:
- `http://localhost/herapower/` → set `'APP_BASE' => '/herapower'` in `config/env.php` (this is how it is set up now).
- `http://herapower.test` → set `'APP_BASE' => ''`.

Any change to `config/env.php` takes effect immediately. On Hostinger at a domain or subdomain root, leave it `''`.

No Apache handy? `php -S 127.0.0.1:8099 -t public` works for a quick look (set `APP_BASE` to `''` for that).

The old `HeraHakatha` folder in `www` is a different, earlier app and is untouched; this one uses its own database (`herapower`).

## Roles

| | operator | supervisor | viewer | admin |
|---|:-:|:-:|:-:|:-:|
| Enter readings (today, yesterday) | ✓ | ✓ | | ✓ |
| Look at earlier days (read-only; set in Settings, default 90 days) | ✓ | ✓ (a year) | ✓ | ✓ (a year) |
| Edit older readings (reason required), override a counter, approve a day | | ✓ | | ✓ |
| Reports | today's daily reports | ✓ | ✓ | ✓ |
| Meters, users, settings, audit | | | | ✓ |

## Commands

| Command | What it does |
|---|---|
| `php tests/run.php` | Entry-rule checks (rolled back; safe on a dev database) |
| `php -d extension=zip tests/import_test.php` | Importer checks on generated workbooks (rolled back) |
| `php cli/migrate.php` | Apply new database migrations |
| `php cli/import.php <file or folder>` | Import history (see below) |
| `php cli/refresh_totals.php [from] [to]` | Rebuild `daily_totals` |
| `php cli/nightly.php` | Refresh yesterday, missing/late check, alert email, backup, prune (hPanel cron 01:30) |
| `php cli/backup.php` | SQL dump to `storage/backups` (no `mysqldump` needed) |

## Layout

```
app/Core        Router, DB, Auth, Csrf, View, Request/Response, helpers
app/Controllers pages + Api/ (JSON) + Admin/
app/Models      Reading (save rules), GensetLog, Meter, Setting
app/Services    Slots (all slot maths), ReadingValidator, Totals, Progress, ReportBuilder, Exporter, Backup, Migrator
app/Services/Import  CellCleaner, MeterIndex, Feeder/Solar/WaterEnergy/Sql parsers, SuspectScanner, Reader, Importer
views/          PHP templates      public/  web root: index.php, sw.js, manifest, assets
database/       schema.sql, seed.sql, migrations/   cli/  command-line tools   storage/  logs, backups
```

## Importing the Excel history (phase 3)

**In the app:** Admin → Import history. Upload one file, pick the month (or let it read the month from the file name), and
you get a preview: readings to add, ones already there, cells that aren't numbers, values to double-check, a day-by-day strip
of what the file covers, and which sheets were read or skipped. Nothing is saved until you press Import. Every import is a
batch that can be undone.

**From the command line** (best for the first big load, no upload limits):
```
php -d extension=zip cli/import.php "C:/path/to/workbooks" --dry-run     # look first
php -d extension=zip cli/import.php "C:/path/to/workbooks"               # import, oldest month first
```
Options: `--month=YYYY-MM`, `--kind=feeders|solar|water_energy|sql`, `--policy=keep|replace-imported`, `--user=<admin>`,
`--sql` (include the old `meter_readings_bulk*.sql`, which overlap the Excel files). The month comes from the folder and
file names. Office temp files, `Thumbs.db`, copies in a second month folder (`2025/June/May`) and files that aren't recognised
logsheets are skipped and listed. On the real 2025–2026 workbooks a dry run reads 55 files in about 30 seconds.

How it treats the data (spec section 13):
- **Dates:** the chosen month plus the first two digits of the sheet name. The date typed inside a sheet is ignored. Sheets whose day doesn't exist in the month (`31.06`) or that aren't daily sheets (`Sheet1`) are skipped, and if two sheets claim one day the one with more readings is used.
- **Columns** are matched by the feeder name in the header, not by position. Spellings like `HV` or `LAUNDRYDRYERS` are recognised, and one mistyped header (a number such as `17.48` where `JSB-9002` should be) is read from its neighbours and noted.
- **Solar:** only the `… Final` sheet is read; the day comes from the column pair and the half-hour from the row, because the time cells are often mistyped. Stray numbers in a time cell are noted.
- **Water & Energy:** only meter readings are imported (Water lines, Inverters, Generators); consumption, totals and kWh/L are recomputed. The old sheets have diesel as litres per day, not a flow-meter reading, so diesel is imported as a running total built from those litres. Daily use and kWh/L then match the sheet. When real flow-meter readings start, the first one may need a supervisor's "meter replaced / reset" override.
- **Not a number** (`11..21`, `7.72.`, `23.8/4`): not imported. It goes to the review list with a suggested fix (`11.21`, `7.72`, `23.84`) that only applies when someone presses **Use**, types a correction, or dismisses it. Cells with no obvious fix (`8.4710.51`) get no suggestion.
- **Looks like a missed decimal point** (`3.54` between 239 and 497, `4451` between 625 and 702): imported, flagged `SPIKE`, and added to the review list with a suggestion. Only an order-of-magnitude gap to both neighbours counts, so loads that really cycle (laundry) are left alone.
- **Existing readings win.** A different value for a slot already in the app is kept (and counted). "Replace earlier imported values" only touches untouched imported rows, never anything entered or edited in the app. Re-importing a file adds nothing and doesn't re-raise review items.
- **Review** (Admin → Import history → review): resolve items one by one, or apply or dismiss a whole kind for a batch. Accepted corrections to imported numbers are written to the audit log.
- **Undo** removes a batch's readings (anything edited afterwards is kept) and refreshes the totals.

Not imported: `Diesel & Energy Consumption 2025.xlsx` (a different layout, not one of the four logsheets in the plan).

On Hostinger without SSH, import small months through the web page (check `upload_max_filesize` and `max_execution_time` in hPanel) or run the CLI from your PC against a copy of the database.

## Deploying to Hostinger

Follow spec section 12. After importing `schema.sql` and `seed.sql` in phpMyAdmin, also import each file in `database/migrations/` in order (or run `php cli/migrate.php` over SSH). In short: PHP 8.2+, create the database and import `schema.sql` then `seed.sql`, upload the folder to
`public_html` (the root `.htaccess` sends traffic into `public/` and blocks the rest), upload `vendor/` or run
`composer install --no-dev`, create `config/env.php` with `APP_ENV => 'production'`, turn on SSL and uncomment the HTTPS
redirect in `public/.htaccess`, then add the nightly cron job. Do **not** run `demo_data.php` in production (it refuses to).

## Open items (from the spec, none block phase 1)

Generator alarm limits (Settings → Generator limits; blank means unchecked), whether all 25 feeders sit under HV Network
(Settings → Compare feeders with HV Network), the diesel meter read time, the new solar plant's inverter count, and the
first real operator/supervisor accounts.
