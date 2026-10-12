# CSRF Coverage — per-file breakdown

Legend:
- ✅  Protected (verifyCsrfOrAbort + csrfField present)
- ⚠️  Partial (one side missing or uses raw `$_SESSION['csrf_token']` instead of helpers)
- ❌  Missing

---

## Core helper

| File | Status | Notes |
|---|---|---|
| `helpers/functions.php` | ✅ | Defines all CSRF helpers |
| `ajax/get_csrf_token.php` | ✅ | Returns fresh token as JSON for JS refreshes |

---

## barangay_admin/

| File | POST handler | Form field | AJAX header | Notes |
|---|---|---|---|---|
| `add_household.php` | ✅ L56 | ✅ L281 | — | Single form |
| `add_resident.php` | ✅ L47 | ✅ L422 | ✅ refreshes via `get_csrf_token.php` | Refreshes token after long form sessions |
| `archive.php` | ✅ L14, L20 | ✅ L295, L365, L370 | — | 3 forms (restore / purge / bulk) |
| `blotter.php` | ✅ L94 | ✅ L1256, L1689 | ✅ `X-CSRF-Token` header on fetch | Meta tag + header pattern |
| `certificates.php` | ✅ L12, L45 | ✅ L2079, L2121 | — | 2 handlers, 2 forms |
| `edit_household.php` | ✅ L64 | ✅ L340 (main), L945 (remove), L952 (add) | — | **3 separate forms** — main, add-member, remove-member all have `csrfField()` |
| `edit_resident.php` | ✅ L89 | ✅ L519 | ✅ refreshes via `get_csrf_token.php` | — |
| `export_blotter.php` | — | — | — | GET export, no POST — no CSRF needed |
| `export_certificates_selected.php` | ✅ L4 | (called via JS POST form — csrf injected by households.php) | — | — |
| `export_households_selected.php` | ✅ L4 | (csrf injected dynamically L790 in households.php) | — | — |
| `export_officials.php` | — | — | — | GET export — no CSRF needed |
| `export_report.php` | — | — | — | GET export — no CSRF needed |
| `export_residents.php` | — | — | — | GET export — no CSRF needed |
| `export_residents_selected.php` | ✅ L4 | (csrf injected dynamically in residents.php L1688) | — | — |
| `forecasting.php` | — | — | — | Read-only page |
| `households.php` | ✅ L24 | ✅ L380 | dynamic inject L790 | Dynamic form built in JS injects csrf from meta |
| `incoming_transfers.php` | ✅ L43 | ✅ L548, L559 | — | — |
| `issue_certificate.php` | ✅ L62 | — | — | ⚠️ Check if form has csrfField — no matching form field found in scan |
| `notices.php` | ✅ L22 | ✅ L528, L631 | — | — |
| `notifications.php` | — | — | — | Read-only list page |
| `officials.php` | ✅ L16 | ✅ L404, L540, L889 (raw `generateCsrfToken()`) | — | Uses raw helper instead of `csrfField()` — functionally identical |
| `reports.php` | ✅ L25, L28 | ✅ L564, L605, L808, L998 | ✅ `fd.append csrf_token` on fetch | — |
| `requests.php` | ✅ L30, L32 | ✅ L267, L336, L343, L355, L424, L457 | ✅ `&csrf_token=` appended L546 | — |
| `residents.php` | ✅ L61 | ✅ L757, L828, L1426, L1435 | ✅ refreshes + dynamic inject | — |
| `settings.php` | ✅ L80, L114 | ✅ many (L491–L1581) | ✅ append csrf_token on fetch | Most complex page — all covered |
| `transfer_requests.php` | ✅ L37, L44 | ✅ L321, L467 | — | — |
| `view_resident.php` | ✅ L14 | ✅ L987 | — | — |

---

## super_admin/

| File | POST handler | Form field | Notes |
|---|---|---|---|
| `barangays.php` | ✅ L15 | ✅ L347, L420, L501 | — |
| `users.php` | ✅ L125 | ✅ L971, L1209, L1352, L1357, L1364, L1421 | — |

---

## Root-level

| File | POST handler | Form field | Notes |
|---|---|---|---|
| `change_password.php` | ✅ L20 | ✅ L244 | — |
| `create_emergency_admin.php` | — | — | **File deleted** — removed from project |
| `landing.php` | ✅ L65, L98, L126, L159, L229 | meta tag + form | Complex multi-step login; refreshes token after each step |
| `verify_2fa.php` | ✅ L28 | ✅ L111 | — |

---

## AJAX endpoints

| File | Verified | Notes |
|---|---|---|
| `ajax/get_csrf_token.php` | ✅ (issues token) | Called by JS to refresh token |
| `ajax/mark_notifications_read.php` | ✅ L4 | Returns new token in response |
| `ajax/save_forecast.php` | ✅ L9 | Expects `X-CSRF-Token` header |
| `ajax/pusher_auth.php` | — | Pusher channel auth — separate auth model |
| Other `ajax/search_*.php` | — | GET/read-only — no CSRF needed |
| `ajax/manual_report.php` | check manually | Not in scan output — verify |
| `ajax/send_notice_notifications.php` | check manually | Not in scan output — verify |

---

## Confirmed findings (verified manually)

| # | File | Finding | Status |
|---|---|---|---|
| 1 | `create_emergency_admin.php` | **Deleted** — file removed from project | ✅ Resolved |
| 2 | `barangay_admin/issue_certificate.php` | `verifyCsrfOrAbort()` at L62 — no HTML `<form>` found (page is print/redirect only, no editable form rendered) | ✅ No form needed |
| 3 | `ajax/manual_report.php` | Uses `verifyCsrfOrAbort()` at L20 (via `verifyCsrfToken()` pattern) | ✅ Protected |
| 4 | `ajax/send_notice_notifications.php` | **Fixed** — added `verifyCsrfOrAbort()` + `X-CSRF-Token` header in `notices.php` fetch call | ✅ Resolved |
