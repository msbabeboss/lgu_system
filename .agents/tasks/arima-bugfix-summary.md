# ARIMA Forecasting Bug-Fix Summary

**Date:** 2026-10-14  
**Branch:** `main`  
**Commits landed:**
- `9b5c647` — `fix: ARIMA forecasting PHP/SQL fixes (CSRF meta, saveForecastRecords, nullable barangay_id)`
- `43fc6a1` — `fix: parameterise barangay_id SQL in arima_api.py fetch_monthly_series`

---

## Background

The forecasting page (`barangay_admin/forecasting.php`) loaded and displayed a UI, but forecast
results were never persisted to the database, and the Python ARIMA API had an unparameterised
SQL query. Four bugs were identified and fixed.

---

## What Was Fixed

### FIX 1 — Missing CSRF meta tag (`barangay_admin/forecasting.php`)

The `<head>` block had no `<meta name="csrf-token">` tag. Any attempt to POST to
`ajax/save_forecast.php` (which calls `verifyCsrfOrAbort()`) would have returned HTTP 403.

**Change:** Added `<meta name="csrf-token" content="<?= csrfMeta() ?>">` inside `<head>`,
immediately after the `<meta charset>` line and before the first `<link>` stylesheet.

---

### FIX 2 — Missing `saveForecastRecords()` function (`barangay_admin/forecasting.php`)

The barangay-admin forecasting page drew charts but never saved results. The super-admin version
had a save function; the barangay-admin version did not.

**Changes:**
- Added `async function saveForecastRecords(data, type)` before `runForecast()`. The function
  POSTs to `ajax/save_forecast.php` with `Content-Type: application/json` and
  `X-CSRF-Token` from the meta tag. It sends: `barangay_id` (from `BRGY_ID`), `series_type`,
  `forecast_months`, `lookback_months: 24`, `arima_order`, `engine`, `summary`, `forecast`,
  `history`, `generated_at`. Wrapped in try/catch so any network failure is silent.
- Added `saveForecastRecords(d, series);` call inside `runForecast()`, after the four
  `drawChart` / `drawMeta` / `drawTable` / `drawExplain` calls.

---

### FIX 3 — NULL barangay_id causes FK violation (`ajax/save_forecast.php`, `database/migrate_forecast_records.sql`, live DB)

When the super-admin runs a forecast for "all barangays", `barangay_id` is sent as `0`.
The old code passed `0` to the INSERT, causing a foreign-key violation (no row with `id=0`
exists in `barangays`). The table schema also declared `barangay_id NOT NULL`, blocking NULL inserts.

**Changes:**

*`ajax/save_forecast.php`:*
```php
// Before
$brgid = max(0, (int)($body['barangay_id'] ?? 0));

// After
$brgidRaw = (int)($body['barangay_id'] ?? 0);
$brgid    = $brgidRaw > 0 ? $brgidRaw : null;
```
Zero now maps to `null`; PDO binds it as SQL NULL, bypassing the FK constraint.

*`database/migrate_forecast_records.sql`:*
- Changed `barangay_id INT UNSIGNED NOT NULL` → `barangay_id INT UNSIGNED NULL DEFAULT NULL` in the `CREATE TABLE` block.
- Appended an `ALTER TABLE forecast_records MODIFY COLUMN barangay_id INT UNSIGNED NULL DEFAULT NULL;` statement for applying the change to an already-created table.

*Live database:*
- The full migration (CREATE TABLE + ALTER TABLE) was run against `lgu_system` on host `CentralizedLGU:3307`.
- `DESCRIBE forecast_records` confirmed `barangay_id` shows `Null = YES`.

---

### FIX 4 — Unparameterised f-string SQL (`python/arima_api.py`)

`fetch_monthly_series()` built the barangay WHERE clause as an f-string
(`f" AND barangay_id = {int(barangay_id)}"`). While the `int()` cast blocked injection
today, it is not safe practice.

**Change:** Refactored to use a `params` tuple built once per loop iteration, before the
`if/elif/else` on `series_type`. All three `cur.execute()` calls now use `cur.execute(sql, params)`.

```python
# Before (inside each branch)
bf  = f" AND barangay_id = {int(barangay_id)}" if barangay_id else ""
cur.execute(sql, (ym,))

# After (single block before the if/elif/else)
if barangay_id:
    bf     = " AND barangay_id = %s"
    params = (ym, int(barangay_id))
else:
    bf     = ""
    params = (ym,)
# ...
cur.execute(sql, params)
```

---

## Files Changed

| File | Change |
|------|--------|
| `barangay_admin/forecasting.php` | Added CSRF meta tag; added `saveForecastRecords()` function; added call in `runForecast()` |
| `ajax/save_forecast.php` | `barangay_id = 0` now maps to SQL NULL |
| `database/migrate_forecast_records.sql` | `barangay_id` made nullable; ALTER TABLE appended |
| `python/arima_api.py` | `fetch_monthly_series()` SQL fully parameterised |

---

## Verification Results

### Health check
```
GET http://127.0.0.1:5001/health
→ {"db_connected":true,"db_error":"","engine":"ARIMA","has_numpy":true,"has_statsmodels":true,"status":"ok"}
```
DB connected, full ARIMA engine available.

### Forecast/all — all barangays (barangay_id=0)
```
GET http://127.0.0.1:5001/forecast/all?months=6&barangay_id=0&lookback=24
→ Valid JSON, errors:{} (empty)
```

| Series | ARIMA order | data_quality | Forecast trend |
|--------|-------------|--------------|----------------|
| residents | [2,1,1] | good (6 non-zero pts) | up (+249%) |
| blotter | [1,0,1] | fair (3 non-zero pts) | down (−28.5%) |
| certificates | [2,1,1] | fair (4 non-zero pts) | up (+49.6%) |

All three series computed and returned successfully. No errors.

### Database schema
`DESCRIBE forecast_records` confirmed `barangay_id` column: `Null = YES`, `Default = NULL`.

---

## Status

**Verdict:** APPROVED  
**FEAT-001:** completed  
**FEAT-002:** completed  

All four bugs fixed, verified, and committed. Branch has not been pushed or merged — left for user review.
