# System Settings Configuration — Documentation

**System:** Talavera LGU Information Management System  
**Version:** 1.0.0  
**Last Updated:** September 14, 2026  
**Scope:** Super Admin (`super_admin/settings.php`) and Barangay Admin (`barangay_admin/settings.php`)

---

## Table of Contents

1. [Overview](#overview)
2. [Database Schema](#database-schema)
3. [Super Admin Settings](#super-admin-settings)
   - [General (Landing Page)](#1-general--landing-page)
   - [Profile](#2-profile-super-admin)
   - [Security](#3-security-super-admin)
   - [Notifications](#4-notifications)
   - [Archive](#5-archive-super-admin)
   - [Appearance](#6-appearance)
   - [Authenticator (2FA)](#7-authenticator-2fa-super-admin)
4. [Barangay Admin Settings](#barangay-admin-settings)
   - [Profile](#1-profile-barangay-admin)
   - [Security](#2-security-barangay-admin)
   - [Archive](#3-archive-barangay-admin)
   - [Authenticator (2FA)](#4-authenticator-2fa-barangay-admin)
5. [Shared System Settings Keys](#shared-system-settings-keys)
6. [Helper Functions](#helper-functions)
7. [Security & Access Control](#security--access-control)
8. [File Locations](#file-locations)
9. [Feature Comparison Matrix](#feature-comparison-matrix)

---

## Overview

The Settings module provides each role with a dedicated configuration interface. Both pages follow the same glassmorphism UI pattern: a sticky left sidebar with tab navigation and a content panel on the right. All changes are persisted to the `system_settings` table (a global key-value store) or to specific entity tables such as `users`, `barangays`, and `landing_content`.

### URL Paths

| Role | URL | Default Tab |
|---|---|---|
| Super Admin | `/super_admin/settings.php` | `general` |
| Barangay Admin | `/barangay_admin/settings.php` | `profile` |

### Tab Navigation (query param `?tab=`)

| Tab | Super Admin | Barangay Admin |
|---|---|---|
| `general` | ✅ Landing page content | ❌ |
| `profile` | ✅ | ✅ |
| `security` | ✅ Full (password rules + lockout) | ✅ Partial (lockout + idle only) |
| `notifications` | ✅ | ❌ |
| `archive` | ✅ (archives users) | ✅ (archives residents) |
| `appearance` | ✅ | ❌ |
| `authenticator` | ✅ | ✅ |

---

## Database Schema

### `system_settings` — Global Key-Value Store

```sql
CREATE TABLE system_settings (
    id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    setting_key VARCHAR(100) NOT NULL UNIQUE,
    setting_val TEXT,
    updated_by  INT UNSIGNED NULL,             -- FK → users.id
    updated_at  TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (updated_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB;
```

> **Note:** This is a shared, unscoped table. Settings saved by the Super Admin and Barangay Admin write to the same rows. There is no per-barangay isolation of settings by design.

### `landing_content` — Public Landing Page (Single Row, id=1)

```sql
CREATE TABLE landing_content (
    id               INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    welcome_title    VARCHAR(200),
    welcome_subtitle VARCHAR(200),
    welcome_desc     TEXT,
    about_text       TEXT,
    contact_phone    VARCHAR(30),
    contact_email    VARCHAR(100),
    contact_address  VARCHAR(255),
    logo1_path       VARCHAR(255),   -- filename relative to uploads/
    logo2_path       VARCHAR(255),
    banner_path      VARCHAR(255),
    updated_at       TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB;
```

### Related Columns Added to `users`

```sql
ALTER TABLE users
    ADD COLUMN login_attempts   TINYINT UNSIGNED DEFAULT 0,
    ADD COLUMN locked_until     DATETIME NULL,
    ADD COLUMN profile_photo    VARCHAR(255) NULL,
    ADD COLUMN totp_secret      VARCHAR(64) NULL,
    ADD COLUMN two_fa_enabled   TINYINT(1) DEFAULT 0;
```

### Related Columns Added to `barangays`

```sql
ALTER TABLE barangays
    ADD COLUMN region    VARCHAR(100) NULL,
    ADD COLUMN province  VARCHAR(100) NULL,
    ADD COLUMN zip_code  VARCHAR(10) NULL,
    ADD COLUMN logo_path VARCHAR(255) NULL;
```

### SQL Migration File

All DDL is also available in:
```
database/migrate_settings.sql
```
Both settings pages also run all migrations inline at page load inside `try/catch` blocks, so the SQL file is optional but recommended for fresh installs.

---

## Super Admin Settings

**File:** `super_admin/settings.php`  
**Role Guard:** `requireRole('super_admin')`  
**Flash Key:** `settings`

### 1. General — Landing Page

**Tab:** `?tab=general`  
**POST Action:** `save_general`  
**Form encoding:** `multipart/form-data` (supports file uploads)

Edits the single row in `landing_content` (id=1). Changes are reflected immediately on the public-facing `/landing.php`.

#### Fields

| Field Name | Input Type | DB Column | Notes |
|---|---|---|---|
| `welcome_title` | text | `landing_content.welcome_title` | Hero headline |
| `welcome_subtitle` | text | `landing_content.welcome_subtitle` | Hero subheading |
| `welcome_desc` | textarea | `landing_content.welcome_desc` | Short hero description |
| `about_text` | textarea | `landing_content.about_text` | About section body |
| `contact_phone` | text | `landing_content.contact_phone` | Numeric only, filtered client-side |
| `contact_email` | email | `landing_content.contact_email` | Email format |
| `contact_address` | text | `landing_content.contact_address` | Freetext address |
| `logo1` | file | `landing_content.logo1_path` | Logo/Seal 1 upload |
| `logo2` | file | `landing_content.logo2_path` | Logo/Seal 2 upload |
| `banner` | file | `landing_content.banner_path` | Hero background image |
| `remove_banner` | hidden `1` | `landing_content.banner_path = NULL` | Clears banner |

#### File Upload Rules (logos and banner)

- Allowed extensions: `jpg`, `jpeg`, `png`, `gif`, `webp`
- Files stored in: `uploads/` (project root)
- Filenames: `logo1_{timestamp}.ext`, `logo2_{timestamp}.ext`, `banner_{timestamp}.ext`
- No MIME double-check at server level in this tab (unlike profile photo tab)

#### Upsert Query

```sql
INSERT INTO landing_content (id, welcome_title, ...) VALUES (1, ?, ...)
ON DUPLICATE KEY UPDATE welcome_title=VALUES(welcome_title), ...
```

#### Activity Log

```
action: UPDATE_SETTINGS | module: Settings | desc: Updated General / Landing Content
```

---

### 2. Profile (Super Admin)

**Tab:** `?tab=profile`  
**POST Actions:** `save_profile`, `save_profile_photo`

#### Personal Information (`save_profile`)

Updates `users` row for the currently logged-in super admin.

| Field | Validation | DB Column |
|---|---|---|
| `first_name` | sanitized | `users.first_name` |
| `middle_name` | sanitized | `users.middle_name` |
| `last_name` | sanitized | `users.last_name` |
| `email` | sanitized | `users.email` |
| `contact` | `^09\d{9}$` (11-digit PH mobile) | `users.contact_number` |
| `position` | sanitized | `users.position` |

After save, session keys `$_SESSION['user_name']` and `$_SESSION['user_email']` are updated to reflect the changes immediately without requiring re-login.

#### Profile Photo (`save_profile_photo`)

Supports both standard form POST and fetch/AJAX. Automatically detects request type by checking for `HTTP_X_CSRF_TOKEN` header or `XMLHttpRequest`.

| Check | Rule |
|---|---|
| Extension | `jpg`, `jpeg`, `png`, `gif`, `webp` |
| MIME (via `finfo`) | `image/jpeg`, `image/png`, `image/gif`, `image/webp` |
| Max size | 2 MB |
| Storage | `uploads/profile_photos/user_{id}_{timestamp}.ext` |
| Session update | `$_SESSION['user_photo']` updated immediately |

**Remove photo:** Set hidden field `remove_photo=1`. Deletes file from disk and sets `users.profile_photo = NULL`.

**AJAX JSON responses:**
```json
{ "ok": true,  "msg": "Photo saved!", "photoUrl": "http://...uploads/profile_photos/..." }
{ "ok": false, "msg": "Invalid file type. Only JPG, PNG, GIF, and WEBP are allowed." }
```

#### Change Password (AJAX Modal)

**POST Action:** `change_password_ajax`  
**Returns:** JSON  

Validation rules:
- All three fields required (current, new, confirm)
- `new_password === confirm_password`
- Minimum 8 characters
- Must contain at least one letter and one number
- Current password verified via `password_verify()`

On success, password is hashed with `PASSWORD_BCRYPT` at cost 12. `must_change_password` flag is reset to `0`.

```json
{ "ok": true,  "msg": "Password changed successfully. You will be logged out now." }
{ "ok": false, "msg": "Current password is incorrect." }
```

---

### 3. Security (Super Admin)

**Tab:** `?tab=security`  
**POST Action:** `save_security`  

Super Admin has the most complete security configuration, including password complexity rules not available to Barangay Admin.

#### Password Policy

| Setting Key | Field | Default | Range | Description |
|---|---|---|---|---|
| `password_min_length` | `password_min_length` | `8` | 6–32 | Minimum password length |
| `password_require_upper` | checkbox | `1` (on) | 0/1 | Require at least one uppercase letter |
| `password_require_number` | checkbox | `1` (on) | 0/1 | Require at least one number |
| `password_require_special` | checkbox | `0` (off) | 0/1 | Require at least one special character |

#### Login Lockout

| Setting Key | Field | Default | Range | Description |
|---|---|---|---|---|
| `login_max_attempts` | `login_max_attempts` | `5` | 1–20 | Failed attempts before account lockout |
| `login_lockout_minutes` | `login_lockout_minutes` | `15` | 1–1440 | How long the lockout lasts |

#### Idle Auto-Logout

| Setting Key | Field | Default | Range | Description |
|---|---|---|---|---|
| `idle_timeout_enabled` | checkbox | `1` (on) | 0/1 | Enable/disable idle timeout |
| `idle_timeout_minutes` | `idle_timeout_minutes` | `5` | 1–120 | Minutes of inactivity before auto-logout |

The idle timeout is enforced client-side via JavaScript that monitors `mousemove`, `keydown`, `scroll`, and `click` events.

#### 2FA Status Card

A read-only card on the Security tab shows the current 2FA status (`enabled`/`disabled`) with a shortcut button linking to the Authenticator tab for full management.

#### Activity Log

```
action: UPDATE_SETTINGS | module: Settings | desc: Updated Security settings
```

---

### 4. Notifications

**Tab:** `?tab=notifications`  
**POST Action:** `save_notifications`  
**Exclusive to:** Super Admin only

Controls system-wide notification delivery behavior.

| Setting Key | Default | Description |
|---|---|---|
| `notif_request_approved` | `1` | Notify residents/users when their document request is approved |
| `notif_request_rejected` | `1` | Notify when request is rejected |
| `notif_request_done` | `1` | Notify when request is marked done/completed |
| `notif_system_alerts` | `1` | Show system-level alerts and LGU announcements |
| `notif_email_enabled` | `0` | Send email copies of notifications (requires mail server in `.env`) |

All toggles use Bootstrap 5 form switches (`<input type="checkbox" role="switch">`).

Email notifications require valid SMTP credentials set in `.env`:
```
MAIL_HOST=smtp.gmail.com
MAIL_PORT=587
MAIL_USERNAME=your@email.com
MAIL_PASSWORD=your_app_password
```

#### Activity Log

```
action: UPDATE_SETTINGS | module: Settings | desc: Updated Notification settings
```

---

### 5. Archive (Super Admin)

**Tab:** `?tab=archive`  
**POST Action:** `save_archive`

#### Auto-Archive Toggle

| Setting Key | Default | Description |
|---|---|---|
| `archive_auto_enabled` | `0` | Master switch for automatic archiving |
| `archive_after_months` | `3` | Records older than N months get auto-archived (1–60) |

The "Archive After" input is only shown when `archive_auto_enabled` is on (client-side toggle via `onchange`).

#### What to Auto-Archive

| Setting Key | Default | Description |
|---|---|---|
| `archive_users` | `1` | Archive inactive user accounts |
| `archive_requests` | `1` | Archive completed or rejected document requests |
| `archive_notices` | `1` | Archive expired announcements/notices |

> **Note:** Super Admin archives `archive_users` (user accounts). Barangay Admin archives `archive_residents` (resident records). These are different keys for different scopes.

#### Archive Stats Card

Displays a live count of total archived records:
```sql
SELECT COUNT(*) FROM archives
```
Includes a direct link to `/super_admin/archive.php`.

#### Activity Log

```
action: UPDATE_SETTINGS | module: Settings | desc: Updated Archive settings
```

---

### 6. Appearance

**Tab:** `?tab=appearance`  
**POST Action:** `save_appearance`  
**Exclusive to:** Super Admin only

Saves user's preferred theme and font scale to `system_settings`. Rendering is handled client-side.

| Setting Key | Field | Default | Options |
|---|---|---|---|
| `appearance_theme` | hidden input `theme` | `light` | `light`, `dark` |
| `appearance_font_size` | hidden input `font_size` | `100` | `85`, `100`, `115` (percentage) |

The form uses hidden inputs that are updated by JavaScript before submit. Theme selection cards and font size slider update the page live before saving.

#### Activity Log

```
action: UPDATE_SETTINGS | module: Settings | desc: Updated Appearance settings
```

---

### 7. Authenticator / 2FA (Super Admin)

**Tab:** `?tab=authenticator`  
**POST Actions:** `generate_2fa`, `enable_2fa`, `disable_2fa`

Uses the TOTP (Time-based One-Time Password) standard, compatible with Google Authenticator, Microsoft Authenticator, and Authy.

#### Flow

```
1. User clicks "Generate QR Code"
   → POST action=generate_2fa
   → TOTP::generateSecret() generates a random base32 secret
   → Stored in users.totp_secret
   → Page shows QR code (rendered via Google Charts API) and manual entry code

2. User scans QR code with authenticator app
   → App generates 6-digit time-based codes every 30 seconds

3. User enters the 6-digit code and clicks "Verify & Enable"
   → POST action=enable_2fa
   → TOTP::verify(secret, code) validates against current and adjacent windows
   → On success: users.two_fa_enabled = 1

4. On next login, user is prompted for their 6-digit code after password
```

#### Disable 2FA

```
POST action=disable_2fa
→ users.two_fa_enabled = 0, users.totp_secret = NULL
```

#### TOTP Helper

```
helpers/totp.php
TOTP::generateSecret()  → returns base32 string
TOTP::verify(secret, code) → returns bool (checks ±1 window for clock drift)
TOTP::getQrCodeUrl(issuer, account, secret) → returns Google Charts QR URL
```

#### Activity Log

```
action: ENABLE_2FA  | module: Security | desc: 2FA enabled
action: DISABLE_2FA | module: Security | desc: 2FA disabled
```

---

## Barangay Admin Settings

**File:** `barangay_admin/settings.php`  
**Role Guard:** `requireRole('barangay_admin')`  
**Flash Key:** `ba_settings`

The barangay admin has 4 tabs (vs. 7 for super admin). The General, Notifications, and Appearance tabs are not available — those are system-wide settings managed exclusively by the Super Admin.

### 1. Profile (Barangay Admin)

**Tab:** `?tab=profile`  
**POST Actions:** `save_profile`, `save_profile_photo`, `save_barangay_logo`

#### Personal Information (`save_profile`)

Identical fields to Super Admin profile (first/middle/last name, email, contact, position). Same 11-digit PH mobile validation (`^09\d{9}$`).

#### Profile Photo (`save_profile_photo`)

Same file validation rules as Super Admin (MIME + extension + 2 MB limit), but uses a standard form POST with redirect rather than fetch/AJAX detection.

Storage: `uploads/profile_photos/user_{id}_{timestamp}.ext`

#### Barangay Information Card (Read-Only)

Displays a non-editable card showing the barangay's registered details from the `barangays` table. These fields can only be edited by the Super Admin via the Barangay List module.

| Displayed Field | Source |
|---|---|
| Region | `barangays.region` |
| Province | `barangays.province` |
| Municipality | (from barangay record) |
| Barangay Name | `barangays.barangay_name` |
| Zip Code | `barangays.zip_code` |

#### Barangay Logo (`save_barangay_logo`)

Allows the barangay admin to upload or remove their barangay's logo.

| Check | Rule |
|---|---|
| Extension | `jpg`, `jpeg`, `png`, `gif`, `webp` |
| MIME (via `finfo`) | `image/jpeg`, `image/png`, `image/gif`, `image/webp` |
| Max size | 2 MB |
| Storage | `uploads/barangay_logos/brgy_{barangay_id}_{timestamp}.ext` |
| DB update | `barangays.logo_path = filename` |

**Remove logo:** Set `remove_logo=1`. Deletes file from disk and sets `barangays.logo_path = NULL`.

#### Change Password (AJAX Modal)

Same as Super Admin — `change_password_ajax` action, JSON response, `PASSWORD_BCRYPT` at cost 12.

---

### 2. Security (Barangay Admin)

**Tab:** `?tab=security`  
**POST Action:** `save_security`

The barangay admin has a **limited** security tab. Password complexity rules are absent — those are a Super Admin-only configuration. Barangay admin can only configure:

#### Login Lockout

| Setting Key | Default | Range | Description |
|---|---|---|---|
| `login_max_attempts` | `5` | 1–20 | Failed login attempts before lockout |
| `login_lockout_minutes` | `15` | 1–1440 | Duration of the lockout |

#### Idle Auto-Logout

| Setting Key | Default | Range | Description |
|---|---|---|---|
| `idle_timeout_enabled` | `1` | 0/1 | Enable idle auto-logout |
| `idle_timeout_minutes` | `5` | 1–120 | Minutes of inactivity before logout |

> ⚠️ Since both roles write to the same `system_settings` table, a Barangay Admin saving these values will overwrite what the Super Admin previously set. This is an existing shared-table design.

#### Activity Log

```
action: UPDATE_SETTINGS | module: Settings | desc: Updated Security settings
```

---

### 3. Archive (Barangay Admin)

**Tab:** `?tab=archive`  
**POST Action:** `save_archive`

Same structure as Super Admin archive tab, but with a different scope — archives **residents** instead of **users**.

#### Auto-Archive Toggle

| Setting Key | Default | Description |
|---|---|---|
| `archive_auto_enabled` | `0` | Master switch for auto-archiving |
| `archive_after_months` | `3` | Archive records older than N months (1–60) |

#### What to Auto-Archive

| Setting Key | Default | Description |
|---|---|---|
| `archive_residents` | `1` | Archive inactive resident records *(barangay-specific key)* |
| `archive_requests` | `1` | Archive completed or rejected document requests |
| `archive_notices` | `1` | Archive expired announcements |

#### Activity Log

```
action: UPDATE_SETTINGS | module: Settings | desc: Updated Archive settings
```

---

### 4. Authenticator / 2FA (Barangay Admin)

**Tab:** `?tab=authenticator`  
**POST Actions:** `generate_2fa`, `enable_2fa`, `disable_2fa`

Identical TOTP flow to Super Admin. Uses the same `helpers/totp.php` library and the same `users.totp_secret` / `users.two_fa_enabled` columns.

#### Activity Log

```
action: ENABLE_2FA  | module: Security | desc: 2FA enabled
action: DISABLE_2FA | module: Security | desc: 2FA disabled
```

---

## Shared System Settings Keys

Complete reference of all keys in the `system_settings` table and which role manages them.

| Setting Key | Default | Super Admin | Barangay Admin | Description |
|---|---|---|---|---|
| `password_min_length` | `8` | ✅ Write | ❌ Read-only | Min password length (6–32) |
| `password_require_upper` | `1` | ✅ Write | ❌ Read-only | Require uppercase letter |
| `password_require_number` | `1` | ✅ Write | ❌ Read-only | Require number |
| `password_require_special` | `0` | ✅ Write | ❌ Read-only | Require special character |
| `login_max_attempts` | `5` | ✅ Write | ✅ Write | Failed attempts before lockout |
| `login_lockout_minutes` | `15` | ✅ Write | ✅ Write | Lockout duration in minutes |
| `session_timeout_minutes` | `60` | ✅ Write | ❌ | Absolute session timeout |
| `idle_timeout_enabled` | `1` | ✅ Write | ✅ Write | Enable idle auto-logout |
| `idle_timeout_minutes` | `5` | ✅ Write | ✅ Write | Idle timeout in minutes |
| `notif_request_approved` | `1` | ✅ Write | ❌ | Notify on request approval |
| `notif_request_rejected` | `1` | ✅ Write | ❌ | Notify on request rejection |
| `notif_request_done` | `1` | ✅ Write | ❌ | Notify on request completion |
| `notif_system_alerts` | `1` | ✅ Write | ❌ | Show system-wide alerts |
| `notif_email_enabled` | `0` | ✅ Write | ❌ | Send email notifications |
| `archive_auto_enabled` | `0` | ✅ Write | ✅ Write | Enable auto-archiving |
| `archive_after_months` | `3` | ✅ Write | ✅ Write | Months before auto-archive |
| `archive_users` | `1` | ✅ Write | ❌ | Auto-archive inactive users |
| `archive_residents` | `1` | ❌ | ✅ Write | Auto-archive inactive residents |
| `archive_requests` | `1` | ✅ Write | ✅ Write | Auto-archive old requests |
| `archive_notices` | `1` | ✅ Write | ✅ Write | Auto-archive expired notices |
| `appearance_theme` | `light` | ✅ Write | ❌ | UI theme preference |
| `appearance_font_size` | `100` | ✅ Write | ❌ | Font scale percentage |

---

## Helper Functions

Both settings pages define local helper functions (not in the global `helpers/` folder).

### Super Admin (`super_admin/settings.php`)

```php
function getSetting(PDO $db, string $key, string $default = ''): string
// Reads a single setting from system_settings. Returns $default if not found.

function saveSetting(PDO $db, string $key, string $val, int $userId): void
// Upserts a setting. INSERT ... ON DUPLICATE KEY UPDATE.
// Records which user last changed it via updated_by column.
```

### Barangay Admin (`barangay_admin/settings.php`)

```php
function baSetting(PDO $db, string $key, string $default = ''): string
// Identical logic to getSetting().

function baSaveSetting(PDO $db, string $key, string $val, int $userId): void
// Identical logic to saveSetting().
```

Both use the same upsert pattern:
```sql
INSERT INTO system_settings (setting_key, setting_val, updated_by)
VALUES (?, ?, ?)
ON DUPLICATE KEY UPDATE
    setting_val  = VALUES(setting_val),
    updated_by   = VALUES(updated_by)
```

### Global Helpers Used (`helpers/functions.php`)

| Function | Purpose |
|---|---|
| `sanitize(string)` | `htmlspecialchars(strip_tags(trim(...)))` — used on all text POST inputs |
| `verifyCsrfOrAbort()` | Validates CSRF token or sends 403. Called at top of every POST block. |
| `csrfField()` | Renders `<input type="hidden" name="csrf_token" value="...">` |
| `flash(key, msg, type)` | Stores a one-time flash message in `$_SESSION` |
| `getFlash(key)` | Retrieves and removes the flash message |
| `logActivity(userId, action, module, desc)` | Writes to `activity_logs` table with IP |
| `redirect(url)` | `header("Location: $url"); exit` |

---

## Security & Access Control

### Role Guards

Every settings page starts with:

```php
// Super Admin
requireRole('super_admin');

// Barangay Admin
requireRole('barangay_admin');
```

`requireRole()` calls `requireLogin()` first (redirects to `landing.php` if no session), then checks `$_SESSION['user_role']`. Mismatch redirects to `landing.php?error=unauthorized`.

### CSRF Protection

All POST handlers start with:
```php
verifyCsrfOrAbort();
```

The CSRF token is stored in `$_SESSION['csrf_token']` and is **not rotated** on each request — this is intentional to prevent race conditions where parallel AJAX calls (e.g. mark-notifications-read) would consume and rotate the token before a form POST could use it. The token is regenerated only on login (`session_regenerate_id(true)`) and logout.

For AJAX/fetch calls, the token is passed via the `X-CSRF-Token` request header.

#### CSRF Coverage — Full Audit (September 14, 2026)

All POST-capable endpoints in the system have been audited and are protected. The table below summarises coverage by location:

| Location | Files | Method | Status |
|---|---|---|---|
| `barangay_admin/*.php` | 14 POST handlers | `verifyCsrfOrAbort()` + `csrfField()` in form | ✅ All protected |
| `super_admin/*.php` | 10 POST handlers | `verifyCsrfOrAbort()` + `csrfField()` in form | ✅ All protected |
| `ajax/mark_notifications_read.php` | 1 | `verifyCsrfOrAbort()` before DB write | ✅ Protected |
| `ajax/save_forecast.php` | 1 | `verifyCsrfOrAbort()` + `X-CSRF-Token` header from JS | ✅ Protected (fixed) |
| `change_password.php` | 1 | `verifyCsrfOrAbort()` + `csrfField()` | ✅ Protected |
| `create_emergency_admin.php` | 1 | Manual `hash_equals()` on `$_SESSION['csrf_token']` + `csrfField()` | ✅ Protected |
| `ajax/mark_notifications_read (1).php` | — | Stale duplicate with no guard | 🗑️ **Deleted** |
| All GET-only `ajax/get_*.php` | 10 | No state mutation | ✅ N/A |
| `ajax/pusher_auth.php` | 1 | POST but no state mutation (channel auth only) | ✅ Acceptable |

#### How fetch()/AJAX Callers Pass the Token

Pages that call POST endpoints via `fetch()` instead of a form submission embed the token in a `<meta>` tag and send it as a request header:

**In the PHP page `<head>`:**
```php
<meta name="csrf-token" content="<?= csrfMeta() ?>">
```

**In the JavaScript fetch call:**
```js
fetch('/ajax/save_forecast.php', {
  method: 'POST',
  headers: {
    'Content-Type': 'application/json',
    'X-CSRF-Token': document.querySelector('meta[name="csrf-token"]').content
  },
  body: JSON.stringify({ /* payload */ })
});
```

`verifyCsrfOrAbort()` checks `$_POST['csrf_token']` first, then falls back to the `HTTP_X_CSRF_TOKEN` server header, so the same function covers both plain form POSTs and fetch()-based AJAX calls.

#### Files Updated in This Audit

| File | Change |
|---|---|
| `ajax/save_forecast.php` | Added `verifyCsrfOrAbort()` after `requireLogin()` |
| `super_admin/forecasting.php` | Added `<meta name="csrf-token">` tag; updated `saveForecastRecords()` to send `X-CSRF-Token` header |
| `ajax/mark_notifications_read (1).php` | **Deleted** — unprotected stale duplicate of `mark_notifications_read.php` |

### File Upload Security

All image uploads (profile photo, barangay logo) use double validation:

1. **Extension check** — whitelist of allowed extensions
2. **MIME type check via `finfo`** — verifies actual file content, not just the filename

The General tab (super admin logo/banner uploads) performs extension check only. A `finfo` MIME check could be added for additional hardening.

### Inline Safe Migrations

Both settings pages run DDL inside `try/catch` blocks at page load:

```php
try {
    $db->exec("ALTER TABLE users ADD COLUMN IF NOT EXISTS login_attempts ...");
} catch (PDOException $e) {}
```

This makes both pages self-healing — they add missing columns and create missing tables without requiring manual SQL execution. The `IF NOT EXISTS` clause (supported by MySQL 5.7+ / MariaDB 10.0+) prevents errors on repeat runs.

---

## File Locations

```
lgu_system/
├── super_admin/
│   └── settings.php              ← Super Admin settings (7 tabs, ~1968 lines)
├── barangay_admin/
│   ├── settings.php              ← Barangay Admin settings (4 tabs, ~1370 lines)
│   └── settings_new.php          ← Empty placeholder (unused)
├── config/
│   ├── config.php                ← App bootstrap, constants, session, .env loader
│   └── database.php              ← Singleton PDO class
├── helpers/
│   ├── auth.php                  ← requireRole(), currentUser(), loginUser()
│   ├── functions.php             ← sanitize(), CSRF, flash, logActivity, etc.
│   ├── totp.php                  ← TOTP 2FA library (generateSecret, verify)
│   ├── mailer.php                ← PHPMailer wrapper for email notifications
│   └── pusher.php                ← Pusher real-time push wrapper
├── includes/
│   ├── sidebar_super.php         ← Super Admin sidebar nav (Settings under "Configuration")
│   ├── sidebar_barangay.php      ← Barangay Admin sidebar nav (Settings under "System")
│   └── topbar.php                ← Shared topbar with notification bell
├── database/
│   └── migrate_settings.sql      ← DDL for system_settings + landing_content + seeds
├── uploads/
│   ├── profile_photos/           ← User profile photos (user_{id}_{ts}.ext)
│   └── barangay_logos/           ← Barangay logos (brgy_{id}_{ts}.ext)
│   (logo1_*.ext, logo2_*.ext, banner_*.ext stored at uploads/ root)
└── .env                          ← DB, mail, Pusher credentials (not committed to VCS)
```

---

## Feature Comparison Matrix

| Feature | Super Admin | Barangay Admin |
|---|---|---|
| **Profile** | | |
| Edit personal info (name, email, contact, position) | ✅ | ✅ |
| Upload / remove profile photo | ✅ | ✅ |
| Upload / remove barangay logo | ❌ | ✅ |
| View barangay address info (read-only) | ❌ | ✅ |
| Change password (AJAX modal) | ✅ | ✅ |
| **Landing Page** | | |
| Edit welcome title / subtitle / description | ✅ | ❌ |
| Edit about section text | ✅ | ❌ |
| Edit contact info (phone, email, address) | ✅ | ❌ |
| Upload Logo 1 / Logo 2 | ✅ | ❌ |
| Upload / remove hero banner image | ✅ | ❌ |
| **Security** | | |
| Set password minimum length | ✅ | ❌ |
| Require uppercase / number / special character | ✅ | ❌ |
| Set login attempt limit and lockout duration | ✅ | ✅ |
| Configure idle auto-logout timeout | ✅ | ✅ |
| **Notifications** | | |
| Toggle request approved / rejected / done alerts | ✅ | ❌ |
| Toggle system alerts | ✅ | ❌ |
| Toggle email notifications | ✅ | ❌ |
| **Archive** | | |
| Enable / disable auto-archiving | ✅ | ✅ |
| Set archive threshold (months) | ✅ | ✅ |
| Auto-archive inactive user accounts | ✅ | ❌ |
| Auto-archive inactive resident records | ❌ | ✅ |
| Auto-archive old requests | ✅ | ✅ |
| Auto-archive expired notices | ✅ | ✅ |
| View archive record count + open archive | ✅ | ✅ |
| **Appearance** | | |
| Switch light / dark theme | ✅ | ❌ |
| Adjust font size scale | ✅ | ❌ |
| **Two-Factor Authentication (2FA)** | | |
| Generate TOTP QR code | ✅ | ✅ |
| Verify and enable 2FA | ✅ | ✅ |
| Disable 2FA | ✅ | ✅ |
| **Audit Trail** | | |
| All changes logged to `activity_logs` | ✅ | ✅ |
| CSRF protection on all POST handlers | ✅ | ✅ |

> **CSRF Audit completed September 14, 2026.** All 27 POST-capable endpoints verified. Two gaps remediated: `ajax/save_forecast.php` (added `verifyCsrfOrAbort()` + JS header), `ajax/mark_notifications_read (1).php` (deleted — unprotected stale duplicate).

---

*Documentation generated for Talavera LGU Information Management System v1.0.0*
