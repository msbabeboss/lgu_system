# Talavera LGU Information Management System
## Complete System Documentation

> **Version:** 1.0.0  
> **Application Name:** Talavera LGU Information Management System  
> **Platform:** PHP / MySQL (XAMPP), Python Flask sidecar  
> **Timezone:** Asia/Manila  
> **Generated:** September 4, 2026

---

## Table of Contents

1. [System Overview](#1-system-overview)
2. [Architecture](#2-architecture)
3. [Roles & Authorization](#3-roles--authorization)
4. [Authentication System](#4-authentication-system)
5. [TOTP Two-Factor Authentication](#5-totp-two-factor-authentication)
6. [Barangay Admin Module](#6-barangay-admin-module)
   - 6.1 [Dashboard](#61-dashboard)
   - 6.2 [Resident Management](#62-resident-management)
   - 6.3 [Household Management](#63-household-management)
   - 6.4 [Blotter Case Management](#64-blotter-case-management)
   - 6.5 [Certificate Issuance](#65-certificate-issuance)
   - 6.6 [Officials Management](#66-officials-management)
   - 6.7 [Notices (View)](#67-notices-view)
   - 6.8 [Requests](#68-requests)
   - 6.9 [Reports & Exports](#69-reports--exports)
   - 6.10 [Archive](#610-archive)
   - 6.11 [Activity & Audit Logs](#611-activity--audit-logs)
   - 6.12 [Settings](#612-settings)
   - 6.13 [Forecasting](#613-forecasting)
7. [Super Admin Module](#7-super-admin-module)
   - 7.1 [Dashboard](#71-dashboard)
   - 7.2 [User Management](#72-user-management)
   - 7.3 [Barangay Management](#73-barangay-management)
   - 7.4 [Notices (Broadcast)](#74-notices-broadcast)
   - 7.5 [Requests (Review)](#75-requests-review)
   - 7.6 [Reports & Exports](#76-reports--exports)
   - 7.7 [Archive](#77-archive)
   - 7.8 [Activity & Audit Logs](#78-activity--audit-logs)
   - 7.9 [Settings](#79-settings)
   - 7.10 [Forecasting](#710-forecasting)
8. [ARIMA Forecasting System](#8-arima-forecasting-system)
   - 8.1 [Architecture Overview](#81-architecture-overview)
   - 8.2 [Python Flask API](#82-python-flask-api)
   - 8.3 [Forecasting Engines](#83-forecasting-engines)
   - 8.4 [PHP Proxy & Auto-Start](#84-php-proxy--auto-start)
   - 8.5 [API Endpoints](#85-api-endpoints)
   - 8.6 [Response Format](#86-response-format)
   - 8.7 [Startup Scripts](#87-startup-scripts)
9. [Real-Time Notifications (Pusher)](#9-real-time-notifications-pusher)
10. [Email System (PHPMailer)](#10-email-system-phpmailer)
11. [Archive System](#11-archive-system)
12. [Settings System](#12-settings-system)
13. [Internal AJAX Endpoints](#13-internal-ajax-endpoints)
14. [External APIs & Third-Party Services](#14-external-apis--third-party-services)
15. [Database Structure](#15-database-structure)
16. [Security Model](#16-security-model)
17. [UI & Frontend Features](#17-ui--frontend-features)
18. [File Structure](#18-file-structure)
19. [Environment Configuration (.env)](#19-environment-configuration-env)
20. [Email Templates Reference](#20-email-templates-reference)

---

## 1. System Overview

The **Talavera LGU Information Management System** is a web-based platform for managing local government operations at the barangay and LGU level in Talavera, Nueva Ecija, Philippines. It centralizes resident records, household data, incident/blotter cases, barangay certificates, and government reports, while enabling secure multi-user access with real-time notifications and predictive analytics.

### Key Capabilities

| Capability | Description |
|---|---|
| Resident Records | Full CRUD with demographics, sectors, photo, address |
| Household Tracking | Household units linked to residents |
| Blotter Management | Incident case tracking with full timeline/audit |
| Certificate Issuance | 9 certificate types with a live designer and print |
| Reports & Exports | Dashboard-level reports, CSV/PDF exports |
| Forecasting | ARIMA-based population/blotter/certificate predictions |
| Real-Time Notifications | Pusher WebSocket push notifications |
| Email Notifications | SMTP via PHPMailer for all key events |
| Two-Factor Auth | TOTP (RFC 6238) for Super Admin accounts |
| Audit Trail | Full activity log with separate audit view |
| Archive/Restore | Soft-delete with full JSON backup and restore |

---

## 2. Architecture

```
Browser (Bootstrap 5 + Chart.js + Pusher JS)
        │
        ▼
PHP Pages (XAMPP / Apache)
  ├── config/config.php        ← bootstrap for every page
  ├── config/database.php      ← PDO singleton
  ├── helpers/auth.php         ← session/role checks
  ├── helpers/functions.php    ← CSRF, logging, notifications
  ├── helpers/mailer.php       ← PHPMailer + email templates
  ├── helpers/pusher.php       ← Pusher PHP SDK wrapper
  ├── helpers/totp.php         ← Custom RFC 6238 TOTP (no deps)
  ├── barangay_admin/          ← Barangay-scoped pages
  ├── super_admin/             ← LGU-wide pages
  └── ajax/                   ← JSON endpoints
        │                         │
        │ HTTP POST/GET            │ file_get_contents()
        ▼                         ▼
    MySQL Database          Python Flask API
    (utf8mb4, PDO)          (127.0.0.1:5001)
                                  │
                            statsmodels / numpy
                            mysql-connector-python
```

### Technology Stack

| Layer | Technology |
|---|---|
| Web Server | Apache (XAMPP) |
| Backend Language | PHP 8.x |
| Database | MySQL (utf8mb4, InnoDB) |
| ORM / DB Access | PDO (singleton pattern, prepared statements) |
| Frontend Framework | Bootstrap 5.3.2 |
| Icons | Bootstrap Icons 1.11.3 + Font Awesome 6.4.0 |
| Charts | Chart.js 4.4.0 |
| Real-Time | Pusher (WebSockets) |
| Email | PHPMailer via Composer |
| Forecasting | Python 3.x + Flask + statsmodels |
| Dependency Manager | Composer (PHP), pip (Python) |

---

## 3. Roles & Authorization

Two roles exist in the system. Every protected page enforces its role at the very top using `requireRole()` or `requireAnyRole()`.

| Role | Scope | Key Privileges |
|---|---|---|
| `super_admin` | System-wide (all barangays) | Manage users, barangays, broadcast notices, review requests, view all data, system settings |
| `barangay_admin` | Single barangay (`barangay_id` from session) | Manage own barangay's residents, households, blotter, certificates, officials |

### Auth Helper Functions (`helpers/auth.php`)

```php
isLoggedIn()           // checks $_SESSION['user_id']
requireLogin()         // redirect to landing.php if not logged in
requireRole($role)     // redirect if wrong role
requireAnyRole($roles) // redirect if not in allowed roles array
isSuperAdmin()         // boolean
isBarangayAdmin()      // boolean
currentUser()          // returns array: id, name, role, barangay_id, email
loginUser($user)       // session_regenerate_id + populate session
logoutUser()           // records logout time, destroys session
```

Session variables set on login:

```
$_SESSION['user_id']
$_SESSION['user_name']
$_SESSION['user_role']
$_SESSION['barangay_id']
$_SESSION['user_email']
$_SESSION['must_change_password']
$_SESSION['login_log_id']
```

---

## 4. Authentication System

All authentication is handled in `landing.php` via `ajax_action` POST parameter (AJAX-first, with non-AJAX fallback).

### 4.1 Login Flow

```
POST ajax_action=login
  → query users WHERE (username OR email) AND status='active'
  → password_verify() against bcrypt hash
  → IF super_admin + two_fa_enabled → redirect to verify_2fa.php
  → ELSE loginUser(), insert login_logs, logActivity('LOGIN')
  → IF must_change_password → return {must_change: true}
  → ELSE return {redirect: dashboard URL}
```

### 4.2 Password Reset Flow

```
POST ajax_action=forgot (email)
  → Always return success (prevent enumeration)
  → IF user found:
      → Invalidate old tokens
      → bin2hex(random_bytes(32)) → 64-char token
      → Store in password_resets (expires in 30 minutes)
      → Send reset email with link: landing.php?reset_token=TOKEN

GET landing.php?reset_token=TOKEN
  → Validate token (not used, not expired)
  → Show reset password form

POST ajax_action=reset (token, new_password, confirm_password)
  → Validate token again
  → Validate password: ≥8 chars, must have letters AND numbers
  → password_hash(bcrypt, cost=12)
  → UPDATE users SET password, must_change_password=0
  → Mark token used
```

### 4.3 First-Login Forced Password Change

```
POST ajax_action=change_password (current_password, new_password, confirm_password)
  → Requires active session (isLoggedIn())
  → Verifies current password
  → Validates new password rules
  → Updates password, clears must_change_password flag
  → Updates session
```

### 4.4 Login Security

| Setting | Default | Configurable In |
|---|---|---|
| Password min length | 8 chars | Super Admin → Settings → General |
| Require uppercase | Yes | Super Admin → Settings → General |
| Require number | Yes | Super Admin → Settings → General |
| Require special char | No | Super Admin → Settings → General |
| Max login attempts | 5 | Super Admin → Settings → General |
| Lockout duration | 15 minutes | Super Admin → Settings → General |
| Idle auto-logout | 5 minutes | Super Admin → Settings → General |
| Session timeout | 60 minutes | Super Admin → Settings → General |

### 4.5 Login Logging

Every login inserts a row into `login_logs`:

```sql
INSERT INTO login_logs (user_id, ip_address) VALUES (?, ?)
```

Logout updates it:

```sql
UPDATE login_logs SET logout_time = NOW() WHERE id = ?
```

---

## 5. TOTP Two-Factor Authentication

Implemented in `helpers/totp.php` — **completely custom, no Composer package required**. Complies with RFC 6238 (TOTP) and is compatible with Google Authenticator, Authy, and Microsoft Authenticator.

### How It Works

```
1. Super Admin clicks "Generate Secret"
   → TOTP::generateSecret()
   → Returns 32-char random Base32 string (160-bit entropy)
   → Saved to users.totp_secret

2. QR Code displayed
   → TOTP::getQRCodeUrl(secret, email, issuer)
   → Builds: otpauth://totp/LGU%20System:email?secret=...&issuer=...
   → Fetches 200×200 PNG from api.qrserver.com

3. Admin scans with authenticator app

4. Admin enters 6-digit code → POST action=enable_2fa
   → TOTP::verify(secret, code)
   → Checks time windows: t-30s, t, t+30s (90-second tolerance)
   → Uses hash_equals() for timing-safe comparison
   → Sets users.two_fa_enabled = 1

5. On next login:
   → Detected: role='super_admin' AND two_fa_enabled=1
   → Session stores: $_SESSION['2fa_pending_user_id']
   → Redirect to verify_2fa.php
   → User enters code → TOTP::verify() → loginUser() proceeds
```

### TOTP Algorithm (RFC 6238)

```
counter  = floor(timestamp / 30)
key      = base32_decode(secret)
msg      = pack('N*', 0) . pack('N*', counter)   // 8-byte big-endian
hash     = HMAC-SHA1(msg, key)
offset   = hash[19] & 0x0F
code     = ((hash[offset] & 0x7F) << 24 | ...) % 1_000_000
→ zero-padded to 6 digits
```

---

## 6. Barangay Admin Module

Located in `barangay_admin/`. Every page begins with `requireRole('barangay_admin')`. All DB queries filter by `barangay_id` from the session.

---

### 6.1 Dashboard

**File:** `barangay_admin/dashboard.php`

Displays a comprehensive overview of all barangay data in a single page.

#### Overview Cards
- Total Residents (Active)
- Total Households
- Active Blotter Cases
- Certificates Issued (all-time)
- Active Officials
- Pending Requests

#### Demographic Charts (Chart.js)
| Chart | Type | Data |
|---|---|---|
| Resident Status | Doughnut | Active / Transferred / Deceased / Inactive |
| Gender | Pie | Male / Female |
| Voter Registration | Pie | Registered / Not Registered |
| Employment | Bar | Employed / Unemployed / Self-Employed / Student / Retired |
| Civil Status | Bar | Single / Married / Widowed / Separated / etc. |
| Population Trend | Line | Last 12 months (monthly count) |
| Blotter Trend | Bar | Last 6 months (monthly count) |
| Certificates by Type | Doughnut | Counts per certificate type |

#### Sector Cards
- PWD (Persons with Disability)
- Senior Citizens (age ≥ 60, auto-tagged)
- Solo Parents
- 4Ps Beneficiaries

#### Additional Panels
- Active Officials table with position/term
- Recent Notices (latest 3 from LGU)
- Pending Requests breakdown by type
- Household statistics (Active, Archived, Total Members, Income Distribution)

---

### 6.2 Resident Management

**File:** `barangay_admin/residents.php`  
**Sub-pages:** `add_resident.php`, `edit_resident.php`, `view_resident.php`

#### Resident Fields

| Field | Notes |
|---|---|
| First / Middle / Last Name | Required |
| Suffix | Jr., Sr., III, etc. |
| Birthdate | Auto-calculates age |
| Age | Display only |
| Civil Status | Single / Married / Widowed / Separated / Annulled / Live-in |
| Gender | Male / Female |
| Contact Number | Validated: must be `09XXXXXXXXX` (11 digits) |
| Voter Status | Registered / Not Registered |
| Special Tags | PWD, Senior (auto if age ≥ 60), 4Ps Beneficiary, Solo Parent |
| Occupation Status | Employed / Unemployed / Self-Employed / Student / Retired |
| Resident Status | Active / Transferred / Deceased / Inactive |
| Transfer Details | Destination barangay, inside/outside municipality, reason |
| Profile Photo | Webcam capture+crop OR file upload (JPG/PNG/WebP/GIF, max 5MB) |
| Address | Linked to `addresses` table: Region, Province, City, Barangay, Street, Purok, Zone, Zip |
| Household | Head (creates new household) or Member of existing |

#### Photo Upload
- Accepts base64 from webcam/crop canvas
- Accepts standard file upload
- MIME type validated via `finfo_file()` (not just extension)
- Stored in `uploads/residents/`

#### Status Transitions
| New Status | Requirements |
|---|---|
| Transferred | Destination barangay required; logs `TRANSFER_RESIDENT` |
| Deceased | Archive reason required; auto-creates archive record; logs `DECEASED_RESIDENT` |
| Inactive | Archive reason required; logs `ARCHIVE_RESIDENT` |

#### List View Features
- Tabs: Active | Transferred (with badge counts)
- Filters: Tag, Gender, Civil Status, Voter Status
- Sort: Newest, Oldest, Name A–Z, Name Z–A
- Contact duplicate check via `ajax/check_contact.php`
- Email sent on new resident add (`sendResidentAddedEmail()`)

---

### 6.3 Household Management

**Files:** `barangay_admin/households.php`, `add_household.php`, `edit_household.php`

#### Household Fields
- Household Code (auto-generated: `HH-{barangay_id}-XXXXX`)
- Head Resident (linked to `residents` table)
- Contact Number
- Address
- Length of Stay
- Income Range
- Total Members / Total Seniors / Total PWD (auto-computed)
- Status: Active / Archived

#### Household Members
Managed via `household_members` junction table:
- `household_id`, `resident_id`, `relationship`
- Prevents adding the same resident to multiple households
- Member count increments/decrements automatically

---

### 6.4 Blotter Case Management

**File:** `barangay_admin/blotter.php`  
**Export:** `barangay_admin/export_blotter.php`

#### Case Number Format
```
BLT-{barangay_id}-{year}-{sequence}
Example: BLT-3-2026-0045
```

#### Case Fields

| Category | Fields |
|---|---|
| Basic | Case number, case title, incident type (13 types), priority (Low/Medium/High/Critical) |
| Complainant | Linked resident OR manual: name, address, contact, gender, age |
| Respondent | Linked resident OR manual: name, address, contact, relationship to complainant |
| Incident | Date, time, location, narrative, incident summary, detailed narrative |
| Status | Open / Under Investigation / Referred / Escalated / Settled / Dismissed / Closed / Archived |
| Resolution | Action taken, officer assigned, date of action, settlement agreement, settlement date, resolution type, remarks, date closed |
| Administration | Recorded by, date recorded, created by |

#### Incident Types
Theft, Physical Assault, Noise Complaint, Domestic Violence, Trespassing, Vandalism, Threat / Intimidation, Estafa / Fraud, Illegal Drugs, Neighborhood Dispute, Animal Bite, Traffic Incident, Other

#### Sub-Records per Case
- **Witnesses** (`blotter_witnesses`): name, address, contact
- **Persons Involved** (`blotter_persons_involved`): linked resident or manual entry, role
- **Attachments** (`blotter_attachments`): files in `uploads/blotter/`, evidence type, description, date
- **Timeline** (`blotter_timeline`): auto-logged on every add/edit

#### Timeline Auto-Logging
Every create or update appends a timeline entry:
```
Event: "Case Created" | "Status Changed: Open → Settled" | "Case Updated"
Fields: status, actor (user name), note (action + officer + resolution)
```

#### On New Case Filed
1. Real-time Pusher notification → current user + all peer admins in same barangay
2. Email to all active super admins (`sendBlotterFiledEmail()`)

#### Filters
- Search: case number, complainant, respondent, location
- Status filter (whitelist-validated)
- Incident type filter
- Date range with presets: Today, Last Day, This Week, Last 30 Days, Last 90 Days, This Year

#### CSV Export (`export_blotter.php`)
- UTF-8 BOM for Excel compatibility
- Selectable by case IDs
- 30+ columns including all case, complainant, respondent, and resolution fields
- Logs `EXPORT_CSV_BLOTTER`

---

### 6.5 Certificate Issuance

**Files:** `barangay_admin/certificates.php`, `issue_certificate.php`, `print_certificate.php`

#### Certificate Types
1. Barangay Clearance
2. Certificate of Indigency
3. Certificate of Residency
4. Business Permit
5. Solo Parent ID / Certification
6. Live-in Certification
7. Same Person Certification
8. BARC Certificate
9. Proof of Low Income

#### Certificate Designer
Full-screen overlay with two panels:
- **Left panel** — input fields (resident name auto-filled by lookup, purpose, date, official names from DB)
- **Right panel** — live print preview of the formatted certificate

Officials (Punong Barangay, Secretary) are auto-populated from `officials` table.

#### Issuance Flow
```
1. Open Certificate Designer
2. Select type → fill fields → resident auto-filled via ajax/get_resident.php
3. Click "Save Record"
   → POST ajax_issue=1
   → INSERT into certificates
   → logActivity('ISSUE_CERTIFICATE')
   → sendCertificateIssuedEmail() to issuing admin
4. Click "Print / PDF" → browser print dialog
```

#### Archive
Moves certificate JSON to `archives` table, deletes from `certificates`.

---

### 6.6 Officials Management

**File:** `barangay_admin/officials.php`

Tracks barangay officials:
- Full name, position, status (active/inactive)
- Term start / term end dates
- Auto-populates into certificate templates
- Dashboard shows active count and expired terms

---

### 6.7 Notices (View)

**File:** `barangay_admin/notices.php`

- Reads notices from the `notices` table where `status='Active'`
- Per-user **dismiss** feature — inserts into `notice_dismissals` (unique per user+notice)
- Dismissed notices are hidden from this view but accessible in Archive → type=notices
- Optional file attachments (PDF/image) linked to notices
- Search by title/content, paginated (20 per page)
- Notices older than 90 days are visually flagged

---

### 6.8 Requests

**File:** `barangay_admin/requests.php`

Barangay admins can submit formal requests to the LGU Super Admin.

#### Request Types
| Type | Description |
|---|---|
| `password_change` | Request password reset assistance |
| `edit_information` | Request to update account information |
| `new_user` | Request a new user account |
| `access_request` | Request additional access/permissions |
| `username_change` | Request username change |
| `reset_2fa` | Request 2FA reset (if locked out) |

Status updates trigger both in-system Pusher notifications and email to the requester.

---

### 6.9 Reports & Exports

**Files:** `barangay_admin/reports.php`, `export_residents.php`, `export_blotter.php`, `export_report.php`

#### Submit Summary to LGU
Compiles a full statistical snapshot and inserts into `reports` table:

**Period options:** Daily, Monthly, Yearly

**Compiled data (25+ stats):**
- Total residents, new residents in period
- Households count
- Gender breakdown (Male/Female)
- Registered voters vs. non-voters
- Sectors: PWD, Senior Citizens, 4Ps, Solo Parents
- Employment: Employed / Unemployed / Self-Employed / Student / Retired
- Certificate totals, certificates issued in period, top certificate type
- Blotter totals, cases in period, by status (Open/Settled/Escalated/Closed)

Prevents duplicate submissions for the same period. Logged as `SUBMIT_REPORT`.

#### Export Options

| Export | Format | File |
|---|---|---|
| Residents Masterlist | Browser print / PDF | `export_residents.php` |
| Blotter Cases | CSV (UTF-8 BOM) | `export_blotter.php` |
| Barangay Report | Print-ready HTML | `export_report.php` |

---

### 6.10 Archive

**File:** `barangay_admin/archive.php`

Manages soft-deleted records from: `residents`, `officials`, `households`, `blotter_cases`, `certificates`.

#### Restore Logic

| Source Table | Restore Action |
|---|---|
| `residents` | `resident_status = 'Active'` |
| `officials` | `status = 'active'` |
| `households` | `status = 'Active'`, clears `archive_reason` |
| `blotter_cases` | `status = 'Closed'` |
| `certificates` | Re-inserts full row from stored JSON |

#### Permanent Delete
- Requires password confirmation (`password_verify()`)
- Cascading cleanup for residents:
  - Deletes associated certificates
  - Nullifies `complainant_id` / `respondent_id` in blotter cases
  - Temporarily disables FK checks for safety
- Deletes from both `archives` and source table
- Logged as `PERMANENT_DELETE`

---

### 6.11 Activity & Audit Logs

**Files:** `barangay_admin/activity_log.php`, `barangay_admin/audit_log.php`

#### Activity Log
Shows ALL actions by users in the current barangay. Full event history including logins, reads, and navigations.

#### Audit Log
Filtered view showing only **write/change actions**:

```
ADD_RESIDENT, EDIT_RESIDENT, ARCHIVE_RESIDENT, DELETE_RESIDENT
ADD_HOUSEHOLD, EDIT_HOUSEHOLD, DELETE_HOUSEHOLD
ADD_BLOTTER, EDIT_BLOTTER, DELETE_BLOTTER
ISSUE_CERTIFICATE, DELETE_CERTIFICATE
ADD_OFFICIAL, EDIT_OFFICIAL, ARCHIVE_OFFICIAL
CHANGE_PASSWORD, RESTORE_ARCHIVE, PERMANENT_DELETE
SAVE_REPORT, SUBMIT_REPORT, ADD_NOTICE, DELETE_NOTICE
```

- **2-year retention** window applied automatically
- Color-coded by action type: green=add, blue=edit, yellow=archive, red=delete
- Filterable by: user, date range (with presets), keyword search
- Paginated (15 per page)

---

### 6.12 Settings

**File:** `barangay_admin/settings.php`

#### Tabs

| Tab | Content |
|---|---|
| Profile | Name, email, contact, position, profile photo (upload/remove) |
| Security | Change own password (requires current password verification) |
| Archive | Auto-archive toggle, age threshold, per-type toggles |
| Authenticator | Redirects to Super Admin settings for 2FA |

Photo upload: JPG/PNG/GIF/WebP, max 2MB, MIME-validated, stored in `uploads/profile_photos/`.

---

### 6.13 Forecasting

**File:** `barangay_admin/forecasting.php`

See [Section 8 — ARIMA Forecasting System](#8-arima-forecasting-system) for full details.

- Scoped to own barangay only (no barangay selector)
- Forecast Horizon: 3 / 6 / 9 / 12 months
- History Window: 12 / 24 / 36 months
- Shows: Residents, Blotter Cases, Certificates charts

---

## 7. Super Admin Module

Located in `super_admin/`. Every page begins with `requireRole('super_admin')`. Queries span all barangays unless a filter is applied.

---

### 7.1 Dashboard

**File:** `super_admin/dashboard.php`

Same statistical categories as Barangay Admin dashboard but aggregated across all barangays. Accepts optional `?barangay_id=N` filter that injects `WHERE barangay_id=?` into all queries.

#### Additional Panels (Super Admin only)
- **Transferred Residents per Barangay** — table of transfers in vs. out
- **Top Barangays by Population** — ranked table with resident count

---

### 7.2 User Management

**File:** `super_admin/users.php`

Full lifecycle management of barangay admin accounts.

#### Create Account
```
1. Fill: first name, last name, email, username, role, barangay assignment
2. Auto-generate temporary password (generatePassword())
3. Set must_change_password = 1
4. Send Welcome Email:
   - Contains username + role
   - "Set My Password" link (password_resets token, valid 24 hours)
5. Log: ADD_USER
```

#### Reset Password
```
1. Super Admin clicks Reset Password on a user
2. New temporary password generated
3. must_change_password = 1
4. Send Password Changed Email with temporary credentials
5. Log: RESET_USER_PASSWORD
```

#### Other Actions
- **Edit** — update profile info, reassign barangay, change role
- **Deactivate / Reactivate** — toggle `users.status`
- **Archive** — saves full user record to `archives`, sets `status='inactive'`

---

### 7.3 Barangay Management

**File:** `super_admin/barangays.php`

#### Fields
- Barangay Name, Municipality, Region, Province, Zip Code
- Status: active / inactive

#### List View Columns
Each row shows live sub-counts via correlated subqueries:
- Total Active Residents
- Total Households
- Total Blotter Cases
- Active Users
- Barangay Captain name + term end

#### Actions
- **Add** / **Edit** barangay details
- **Archive** — sets `status='inactive'`, saves to `archives`
- Filtered by name/municipality + status, paginated

---

### 7.4 Notices (Broadcast)

**File:** `super_admin/notices.php`

#### Create Notice
```
1. Fill: title, content (text), optional file (PDF/JPG/JPEG/PNG → uploads/notices/)
2. INSERT into notices
3. For every active barangay_admin:
   → sendNotification() → Pusher real-time push
4. sendNoticeBroadcastEmails() → bulk HTML email to all admins
5. Log: ADD_NOTICE
```

#### Archive Notice
Sets `status='Archived'`, saves to `archives` table. Logged as `ARCHIVE_NOTICE`.

---

### 7.5 Requests (Review)

**File:** `super_admin/requests.php`

Reviews requests submitted by barangay admins.

#### Request Status Pipeline

```
pending → viewed → processing → approved / rejected → done → archived
```

| Action | Trigger | Notification Sent |
|---|---|---|
| viewed | Super admin opens request (AJAX) | In-system + email to requester |
| processing | Manual status update | In-system + email to requester |
| approved | Manual with optional notes | In-system + email to requester |
| rejected | Manual with notes (reason) | In-system + email to requester |
| done | Manual | In-system + email to requester |
| archived | Manual | Saved to `archives` table |

---

### 7.6 Reports & Exports

**Files:** `super_admin/reports.php`, `export_report_lgu.php`, `export_barangays.php`

System-wide report view aggregating submissions from all barangays. Export to print-ready HTML or CSV.

---

### 7.7 Archive

**File:** `super_admin/archive.php`

Scoped to LGU-level records: `users`, `barangays`, `notices`, `requests`.

**Restore logic:**

| Table | Restore Action |
|---|---|
| `users` | `status = 'active'` |
| `barangays` | `status = 'active'` |
| `notices` | `status = 'Active'` |
| `requests` | `status = 'pending'` |

Permanent delete requires password confirmation. Does not touch barangay-level records.

---

### 7.8 Activity & Audit Logs

**Files:** `super_admin/activity_log.php`, `super_admin/audit_log.php`

Same structure as barangay logs but scoped to all barangays and LGU-level actions.

---

### 7.9 Settings

**File:** `super_admin/settings.php`

#### Tabs

| Tab | Content |
|---|---|
| General | Password policy, login lockout, session timeout, idle timeout |
| Profile | Name, email, contact, position, profile photo |
| Security | Change own password |
| Notifications | Toggle: on request approved/rejected/done, system alerts, email notifications |
| Archive | Auto-archive rules and per-type toggles |
| Appearance | Landing page: welcome title, subtitle, description, about text, contact info, logo 1, logo 2, banner image |
| Authenticator | 2FA setup and management (QR code, enable, disable) |

#### Password Policy Settings
```
password_min_length       (default: 8)
password_require_upper    (default: 1)
password_require_number   (default: 1)
password_require_special  (default: 0)
login_max_attempts        (default: 5)
login_lockout_minutes     (default: 15)
session_timeout_minutes   (default: 60)
idle_timeout_enabled      (default: 1)
idle_timeout_minutes      (default: 5)
```

---

### 7.10 Forecasting

**File:** `super_admin/forecasting.php`

Same as barangay forecasting but includes a **Barangay Filter** dropdown — can view forecasts for any individual barangay or system-wide (all barangays combined).

---

## 8. ARIMA Forecasting System

### 8.1 Architecture Overview

PHP cannot natively run statistical models, so a **Python Flask microservice** runs on `http://127.0.0.1:5001` alongside the PHP application. PHP communicates via HTTP using `file_get_contents()`.

```
Browser
  → forecasting.php
    → fetch() to ajax/get_arima_forecast.php  (PHP proxy)
      → HTTP GET to 127.0.0.1:5001/forecast   (Python Flask)
        → MySQL query (historical monthly counts)
        → ARIMA model (statsmodels) OR Holt-Winters fallback
        ← JSON: history + forecast + CI + summary
      ← JSON forwarded
    ← JSON response
  → Chart.js renders chart with confidence interval shading
```

---

### 8.2 Python Flask API

**File:** `python/arima_api.py`

```python
# Flask app on port 5001, CORS enabled
app = Flask(__name__)
CORS(app)

# Optional dependency detection
HAS_NUMPY        # numpy installed?
HAS_STATSMODELS  # statsmodels installed?
HAS_MYSQL        # mysql-connector-python installed?
```

Reads the same `.env` file as PHP for database credentials.

#### Data Fetching (`fetch_monthly_series`)
Iterates backward `lookback_months` (default 24) from today, executing a `COUNT(*)` per calendar month:

| Series Type | Table Queried | Filter |
|---|---|---|
| `residents` | `residents` | `created_at` month + optional `barangay_id` |
| `blotter` | `blotter_cases` | `created_at` month, `status != 'Archived'` |
| `certificates` | `certificates` | `created_at` month + optional `barangay_id` |

---

### 8.3 Forecasting Engines

#### Engine Selection Logic

```python
if len(values) >= 6 AND HAS_STATSMODELS AND HAS_NUMPY:
    try:
        → use ARIMA (statsmodels)
    except:
        → fall through to Holt-Winters
else:
    → use Holt-Winters (pure Python, no deps)
```

---

#### Engine 1: Real ARIMA (statsmodels)

**Function:** `statsmodels_forecast(values, steps)`

```
Step 1: Use last 24 months of data
        Reject if < 4 non-zero points → fall back to Holt

Step 2: Auto-select order via ADF test (_auto_order):
        - ADF p-value < 0.05 → stationary → d = 0
        - ADF p-value ≥ 0.05 → non-stationary → d = 1
        - σ > 0.5 × mean (high volatility) → p = 2
        - σ ≤ 0.5 × mean → p = 1
        - Always q = 1

Step 3: Try candidate orders in sequence:
        [auto_order, (1,1,1), (2,1,1), (1,0,1), (1,0,0), (0,1,1)]
        Use first that fits successfully

Step 4: Sanity check:
        Reject model if max(forecast) > 8 × historical_mean
        (prevents runaway projections)

Step 5: Return:
        - forecast mean (clamped to ≥ 0)
        - 80% confidence interval (conf_int alpha=0.2)
        - used ARIMA order tuple
```

---

#### Engine 2: Holt-Winters Double Exponential Smoothing (fallback)

**Function:** `fallback_forecast(values, steps)`

Pure Python — no numpy or statsmodels required.

```
Parameters:
  α = 0.30   (level smoothing)
  β = 0.10   (trend smoothing)
  φ = 0.88   (trend damping factor, Gardner & McKenzie method)

Steps:
  1. Use last 12 non-zero data points as working window
  2. Initialize level and trend from working window
  3. Run exponential smoothing over working window
  4. Project h steps ahead with damped trend:
       forecast[h] = level + (φ + φ² + ... + φ^h) × trend
  5. Confidence interval:
       width = z × MAE × √(1 + h × 0.15)
       z = 1.28  (80% CI)
       lower = max(0, forecast - width)
       upper = forecast + width
```

---

#### Response Summary Fields

| Field | Description |
|---|---|
| `engine` | `"ARIMA"` or `"Holt"` |
| `arima_order` | e.g. `[1, 1, 1]` |
| `data_quality` | `"good"` (≥6 non-zero), `"fair"` (≥3), `"sparse"` (<3) |
| `trend_direction` | `"up"` (>2%), `"down"` (<-2%), `"stable"` |
| `trend_pct` | Percentage change from historical avg to forecast avg |
| `avg_historical` | Mean of all historical values |
| `avg_forecast` | Mean of forecast values |

> When `data_quality = "sparse"`, trend is always reported as `"stable"` with `trend_pct = 0.0` to avoid misleading projections.

---

### 8.4 PHP Proxy & Auto-Start

**File:** `ajax/get_arima_forecast.php`

#### Parameter Validation

| Parameter | Values | Default |
|---|---|---|
| `type` | `residents`, `blotter`, `certificates`, `all` | `all` |
| `months` | 1 – 12 | 6 |
| `barangay_id` | ≥ 0 | 0 (all) |
| `lookback` | 6 – 36 | 24 |

#### Auto-Start Logic (if API unreachable)

```
Attempt 1: Try to start via VBS launcher (hidden window, no console)
           Path: Windows Startup folder / LGU_ARIMA_API.vbs

Attempt 2: Use pythonw.exe directly (no console window)
           str_replace('python.exe', 'pythonw.exe', PYTHON_EXE)

Attempt 3: Fallback to python.exe
           popen('start /b "" "python.exe" "arima_api.py"', 'r')

Then: Poll every 1 second for up to 8 seconds
      → If alive: forward response
      → If still down: return HTTP 503 with manual-start hint
```

---

### 8.5 API Endpoints

| Method | Path | Description |
|---|---|---|
| GET | `/health` | Returns DB status, dep flags, engine type, timestamp |
| GET | `/forecast` | Forecast one series type |
| GET | `/forecast/all` | Forecast all three series in one call |

#### `/forecast` Query Parameters

| Parameter | Description | Default |
|---|---|---|
| `type` | `residents`, `blotter`, or `certificates` | required |
| `months` | Forecast horizon (1–12) | 6 |
| `barangay_id` | 0 = all barangays | 0 |
| `lookback` | Historical months to include | 24 |

---

### 8.6 Response Format

```json
{
  "type": "residents",
  "barangay_id": 0,
  "engine": "ARIMA",
  "arima_order": [1, 1, 1],
  "history": {
    "labels": ["Sep 2024", "Oct 2024", "..."],
    "values": [12, 8, 15, "..."]
  },
  "forecast": {
    "labels": ["Oct 2026", "Nov 2026", "..."],
    "values": [18.5, 19.2, "..."],
    "lower_ci": [14.2, 14.8, "..."],
    "upper_ci": [22.8, 23.6, "..."]
  },
  "summary": {
    "avg_historical": 11.4,
    "avg_forecast": 18.9,
    "trend_pct": 65.8,
    "trend_direction": "up",
    "data_points": 24,
    "non_zero_points": 18,
    "data_quality": "good"
  }
}
```

---

### 8.7 Startup Scripts

| File | Purpose |
|---|---|
| `python/start_api.bat` | Manually launch the Flask server |
| `python/run_api_hidden.vbs` | VBScript — runs `start_api.bat` hidden (no console window) |
| `python/register_startup.bat` | Copies VBS to Windows Startup folder (auto-start on boot) |
| `python/install_deps.bat` | Installs Python packages from `requirements.txt` |
| `python/requirements.txt` | `flask`, `flask-cors`, `statsmodels`, `numpy`, `mysql-connector-python`, `python-dateutil` |

---

## 9. Real-Time Notifications (Pusher)

### Configuration

All credentials loaded from `.env`:
```
PUSHER_APP_ID=
PUSHER_KEY=
PUSHER_SECRET=
PUSHER_CLUSTER=mt1
```

If any of `APP_ID`, `KEY`, or `SECRET` are empty, **all Pusher features gracefully degrade** — no error is thrown.

### Server Side (`helpers/pusher.php`)

```php
getPusher(): ?\Pusher\Pusher
// Singleton. Returns null if not configured.

pushNotification(int $userId, int $notifId, string $message, string $type, string $createdAt): void
// Triggers event 'new-notification' on channel 'private-user-{userId}'
// Payload: { id, message, type, created_at }
```

### Notification Flow

```
sendNotification($userId, $message, $type)  ← functions.php
  → INSERT INTO notifications (user_id, message, type)
  → pushNotification($userId, ...)  ← pusher.php
    → $pusher->trigger("private-user-{$userId}", "new-notification", [...])
```

### Channel Authentication (`ajax/pusher_auth.php`)

Pusher requires server-side auth for private channels. The endpoint:
1. Verifies `isLoggedIn()` 
2. Confirms the channel matches the logged-in user's ID
3. Returns the signed auth token

### Client Side (`includes/topbar.php`)

When `PUSHER_KEY` is configured:
```javascript
Pusher.logToConsole = false;
const pusher = new Pusher(PUSHER_KEY, { cluster: PUSHER_CLUSTER, authEndpoint: '/ajax/pusher_auth.php' });
const channel = pusher.subscribe('private-user-' + userId);

channel.bind('new-notification', function(data) {
    prependNotif(data);        // inject DOM element at top of dropdown
    unreadCount++;
    refreshBadge();            // update bell badge count
    bell.classList.add('notif-bell-pulse');  // animate bell
    // Browser Notification API (if permission granted)
    new Notification('New Notification', { body: data.message });
});
```

### Notification Triggers in the System

| Event | Recipients |
|---|---|
| New blotter case filed | Filing admin + all peer admins in same barangay |
| New blotter case (email) | All active super admins |
| Notice created | All active barangay admins (in-system + email) |
| Request status changed | Requester (in-system + email) |
| Any `sendNotification()` call | Specified user ID |

### Mark as Read

Fetch call to `ajax/mark_notifications_read.php` with `X-CSRF-Token` header. Updates `notifications.is_read = 1` for all unread records of that user. Badge clears, items visually marked as read.

---

## 10. Email System (PHPMailer)

### Configuration (`.env`)

```
MAIL_HOST=smtp.gmail.com
MAIL_PORT=587
MAIL_USERNAME=your@email.com
MAIL_PASSWORD=your-app-password
MAIL_FROM_EMAIL=your@email.com
MAIL_FROM_NAME=Talavera LGU Information Management System
APP_URL=https://your-public-url.com   # for reset links
```

Encryption: `STARTTLS`. Timeout: 10 seconds.

### Core Function

```php
sendMail(string $toEmail, string $toName, string $subject, string $htmlBody): bool
```

Returns `false` on failure; errors logged via `error_log()`. Never throws to the caller.

### Email Templates

All emails are HTML-formatted with gradient headers, branded colors, and action buttons.

| Function | Subject | Trigger | Recipients |
|---|---|---|---|
| `buildResetEmailHtml()` | "Password Reset — {APP_NAME}" | Forgot password | The requesting user |
| `sendWelcomeEmail()` | "Your Account Has Been Created" | New user created | New barangay admin |
| `sendPasswordChangedEmail()` | "Your Password Has Been Reset" | Password reset by super admin | Affected barangay admin |
| `sendCertificateIssuedEmail()` | Certificate issued confirmation | Certificate saved | Issuing admin |
| `sendBlotterFiledEmail()` | "New Blotter Case Filed" | New blotter case | All active super admins |
| `sendNoticeBroadcastEmails()` | "New Notice: {title}" | Notice created | All active barangay admins |
| `sendResidentAddedEmail()` | "New Resident Added" | Resident added | Adding barangay admin |
| `sendNewRequestEmailToAdmins()` | "New Request Submitted" | Request filed | All active super admins |
| `sendRequestStatusUpdateEmail()` | "Request Update: {subject}" | Status change | Requesting barangay admin |

> `EMAIL_BASE_URL` (from `APP_URL` in `.env`) is used for all links so they work from LAN IPs or ngrok tunnels, not just localhost.

---

## 11. Archive System

### Core Function (`helpers/functions.php`)

```php
archiveRecord(int $userId, string $tableName, int $recordId, array $recordData, ?string $archiveReason = null): bool
```

Serializes the full record as JSON into the `archives` table:

```sql
INSERT INTO archives (table_name, record_id, data_json, archived_by)
VALUES (?, ?, ?, ?)
```

### Archives Table Structure

| Column | Type | Description |
|---|---|---|
| `id` | INT UNSIGNED | Primary key |
| `table_name` | VARCHAR | Source table name |
| `record_id` | INT UNSIGNED | Original record ID |
| `data_json` | LONGTEXT | Full record serialized as JSON |
| `archived_by` | INT UNSIGNED | User ID who archived |
| `archived_at` | TIMESTAMP | When archived |

### Scoping by Role

| Role | Can Archive/Restore | Tables |
|---|---|---|
| `barangay_admin` | Own barangay records only | `residents`, `officials`, `households`, `blotter_cases`, `certificates` |
| `super_admin` | LGU-level records only | `users`, `barangays`, `notices`, `requests` |

---

## 12. Settings System

All settings stored in `system_settings` table as key-value pairs:

```sql
CREATE TABLE system_settings (
    setting_key VARCHAR(100) NOT NULL UNIQUE,
    setting_val TEXT,
    updated_by INT UNSIGNED NULL,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
)
```

Upserted via `ON DUPLICATE KEY UPDATE`. Read via `getSetting()` / `baSetting()` helpers.

### All Setting Keys

| Key | Default | Description |
|---|---|---|
| `password_min_length` | 8 | Minimum password length |
| `password_require_upper` | 1 | Require uppercase letter |
| `password_require_number` | 1 | Require at least one number |
| `password_require_special` | 0 | Require special character |
| `login_max_attempts` | 5 | Before lockout |
| `login_lockout_minutes` | 15 | Lockout duration |
| `session_timeout_minutes` | 60 | Session expiry |
| `idle_timeout_enabled` | 1 | Enable idle auto-logout |
| `idle_timeout_minutes` | 5 | Idle timeout duration |
| `notif_request_approved` | 1 | Email on request approved |
| `notif_request_rejected` | 1 | Email on request rejected |
| `notif_request_done` | 1 | Email on request done |
| `notif_system_alerts` | 1 | System alert notifications |
| `notif_email_enabled` | 0 | Global email notifications toggle |
| `archive_auto_enabled` | 0 | Auto-archive old records |
| `archive_after_months` | 3 | Age threshold for auto-archive |
| `archive_users` | 1 | Auto-archive inactive users |
| `archive_requests` | 1 | Auto-archive old requests |
| `archive_notices` | 1 | Auto-archive old notices |
| `archive_residents` | 1 | Auto-archive inactive residents |

---

## 13. Internal AJAX Endpoints

All located in `ajax/`. Most require authentication (`requireLogin()`).

| File | Auth | Method | Purpose |
|---|---|---|---|
| `get_arima_forecast.php` | Required | GET | PHP → Python forecasting proxy |
| `get_certificate.php` | Required | GET | Certificate data for print preview |
| `get_households.php` | Required | GET | Household list for dropdowns |
| `get_household_members.php` | Required | GET | Members of a specific household |
| `get_resident.php` | Required | GET | Resident data for form auto-fill |
| `get_resident (1).php` | Required | GET | Alternate resident lookup |
| `get_resident_address.php` | Required | GET | Resident address lookup |
| `get_landing_stats.php` | **None (public)** | GET | Live counter stats for landing page |
| `mark_notifications_read.php` | Required + CSRF | POST | Mark all notifications as read |
| `mark_notifications_read (1).php` | Required + CSRF | POST | Alternate mark-read endpoint |
| `check_contact.php` | Required | GET | Duplicate contact number check |
| `pusher_auth.php` | Required | POST | Authorize Pusher private channel |

### `get_landing_stats.php` (Public Endpoint)

Returns live counts for the animated hero counters on the public landing page:

```json
{
  "residents": 1234,
  "users": 15,
  "records": 5678
}
```

`records` = total of residents + households + blotter_cases + certificates + officials + archives.

---

## 14. External APIs & Third-Party Services

| Service | Type | SDK / Version | Purpose |
|---|---|---|---|
| **Pusher** | WebSocket SaaS | `pusher/pusher-php-server` (PHP), `pusher.min.js` v8.4 (JS) | Real-time push notifications; private per-user channels |
| **PHPMailer** | SMTP library | `phpmailer/phpmailer` via Composer | HTML transactional email (password reset, alerts, confirmations) |
| **qrserver.com** | REST API | `GET https://api.qrserver.com/v1/create-qr-code/` | TOTP QR code image generation for 2FA enrollment |
| **Bootstrap** | CDN CSS/JS | 5.3.2 | UI framework (grid, components, modals, alerts) |
| **Bootstrap Icons** | CDN font | 1.11.3 | Icon set used throughout the application |
| **Chart.js** | CDN JS | 4.4.0 | Dashboard charts (line, bar, pie, doughnut) |
| **Font Awesome** | CDN font | 6.4.0 (cdnjs) | Landing page icons |
| **Google Fonts** | CDN | — | Roboto font on landing page |
| **Python Flask** | Local microservice | Port 5001 | ARIMA forecasting API (internal, not external) |
| **statsmodels** | Python package | pip | ARIMA model computation and ADF test |
| **numpy** | Python package | pip | Numerical operations for ARIMA |
| **mysql-connector-python** | Python package | pip | Direct MySQL access from Python API |

### qrserver.com API Details

```
URL: https://api.qrserver.com/v1/create-qr-code/
Parameters:
  size=200x200
  data=otpauth%3A%2F%2Ftotp%2FLGU%2520System%3A{email}%3Fsecret%3D{secret}%26issuer%3D...
Method: GET
Response: 200×200 PNG image
Authentication: None required
```

---

## 15. Database Structure

### Core Tables

#### `users`
| Column | Type | Notes |
|---|---|---|
| `id` | INT UNSIGNED PK | |
| `username` | VARCHAR(50) UNIQUE | Login identifier |
| `email` | VARCHAR(100) UNIQUE | Also used as login identifier |
| `password` | VARCHAR(255) | bcrypt hash, cost 12 |
| `first_name`, `middle_name`, `last_name` | VARCHAR | |
| `role` | ENUM('super_admin','barangay_admin') | |
| `barangay_id` | INT UNSIGNED FK | NULL for super_admin |
| `status` | ENUM('active','inactive') | |
| `must_change_password` | TINYINT(1) | |
| `two_fa_enabled` | TINYINT(1) | |
| `totp_secret` | VARCHAR(64) | Base32 encoded |
| `profile_photo` | VARCHAR(255) | Filename in uploads/profile_photos/ |
| `contact_number` | VARCHAR(20) | |
| `position` | VARCHAR(100) | |
| `login_attempts` | TINYINT UNSIGNED | |
| `locked_until` | DATETIME | |
| `created_at` | TIMESTAMP | |

#### `barangays`
| Column | Type |
|---|---|
| `id` | INT UNSIGNED PK |
| `barangay_name` | VARCHAR(100) |
| `municipality` | VARCHAR(100) |
| `region` | VARCHAR(100) |
| `province` | VARCHAR(100) |
| `zip_code` | VARCHAR(10) |
| `logo_path` | VARCHAR(255) |
| `status` | ENUM('active','inactive') |

#### `residents`
| Column | Type |
|---|---|
| `id` | INT UNSIGNED PK |
| `barangay_id` | INT UNSIGNED FK |
| `first_name`, `middle_name`, `last_name` | VARCHAR |
| `suffix` | VARCHAR(10) |
| `birthdate` | DATE |
| `age` | TINYINT UNSIGNED |
| `gender` | ENUM('Male','Female') |
| `civil_status` | VARCHAR(20) |
| `contact_number` | VARCHAR(20) |
| `address_id` | INT UNSIGNED FK |
| `voter_status` | ENUM('Registered','Not Registered') |
| `is_pwd` | TINYINT(1) |
| `is_senior` | TINYINT(1) |
| `is_4ps` | TINYINT(1) |
| `is_solo_parent` | TINYINT(1) |
| `occupation_status` | ENUM |
| `resident_status` | ENUM('Active','Inactive','Transferred','Deceased') |
| `photo` | VARCHAR(255) |
| `transfer_from/to_barangay` | VARCHAR |
| `transfer_outside` | TINYINT(1) |
| `transfer_reason` | TEXT |
| `archive_reason` | TEXT |
| `created_at` | TIMESTAMP |

#### `households`
| Column | Type |
|---|---|
| `id` | INT UNSIGNED PK |
| `barangay_id` | INT UNSIGNED FK |
| `household_code` | VARCHAR(30) UNIQUE |
| `head_resident_id` | INT UNSIGNED FK |
| `contact_number` | VARCHAR(20) |
| `address_id` | INT UNSIGNED FK |
| `length_of_stay` | VARCHAR(50) |
| `income_range` | VARCHAR(50) |
| `total_members` | TINYINT UNSIGNED |
| `total_senior` | TINYINT UNSIGNED |
| `total_pwd` | TINYINT UNSIGNED |
| `status` | ENUM('Active','Archived') |
| `archive_reason` | TEXT |

#### `blotter_cases`
| Column | Type |
|---|---|
| `id` | INT UNSIGNED PK |
| `barangay_id` | INT UNSIGNED FK |
| `case_number` | VARCHAR(30) UNIQUE |
| `case_title` | VARCHAR(200) |
| `incident_type` | VARCHAR(50) |
| `priority` | ENUM('Low','Medium','High','Critical') |
| `complainant_id` | INT UNSIGNED FK (residents) |
| `complainant_name_manual` | VARCHAR(150) |
| `complainant_address/contact/gender/age` | various |
| `respondent_id` | INT UNSIGNED FK (residents) |
| `respondent_name_manual` | VARCHAR(150) |
| `respondent_address/contact/relationship` | various |
| `incident_date` | DATE |
| `incident_time` | TIME |
| `date_reported` | DATETIME |
| `location` | VARCHAR(255) |
| `narrative` | TEXT |
| `incident_summary` | TEXT |
| `detailed_narrative` | TEXT |
| `status` | ENUM (Open/Under Investigation/Referred/Escalated/Settled/Dismissed/Closed/Archived) |
| `action_taken` | TEXT |
| `officer_assigned` | VARCHAR(150) |
| `date_of_action` | DATE |
| `settlement_agreement` | TINYINT(1) |
| `settlement_date` | DATE |
| `resolution` | ENUM('Pending','Settled','Referred','Dismissed') |
| `remarks` | TEXT |
| `date_closed` | DATE |
| `recorded_by` | VARCHAR(150) |
| `date_recorded` | DATETIME |
| `created_by` | INT UNSIGNED FK |
| `created_at` | TIMESTAMP |

#### `certificates`
| Column | Type |
|---|---|
| `id` | INT UNSIGNED PK |
| `barangay_id` | INT UNSIGNED FK |
| `resident_id` | INT UNSIGNED FK |
| `certificate_type` | VARCHAR(80) |
| `purpose` | TEXT |
| `issued_by` | VARCHAR(150) |
| `date_issued` | DATE |
| `created_at` | TIMESTAMP |

#### `officials`
| Column | Type |
|---|---|
| `id` | INT UNSIGNED PK |
| `barangay_id` | INT UNSIGNED FK |
| `full_name` | VARCHAR(150) |
| `position` | VARCHAR(100) |
| `status` | ENUM('active','inactive') |
| `term_start` | DATE |
| `term_end` | DATE |

#### `notifications`
| Column | Type |
|---|---|
| `id` | INT UNSIGNED PK |
| `user_id` | INT UNSIGNED FK |
| `message` | TEXT |
| `type` | ENUM('info','success','warning','danger') |
| `is_read` | TINYINT(1) |
| `created_at` | TIMESTAMP |

#### `activity_logs`
| Column | Type |
|---|---|
| `id` | INT UNSIGNED PK |
| `user_id` | INT UNSIGNED FK |
| `action` | VARCHAR(100) |
| `module` | VARCHAR(100) |
| `description` | TEXT |
| `ip_address` | VARCHAR(45) |
| `created_at` | TIMESTAMP |

#### `login_logs`
| Column | Type |
|---|---|
| `id` | INT UNSIGNED PK |
| `user_id` | INT UNSIGNED FK |
| `ip_address` | VARCHAR(45) |
| `login_time` | TIMESTAMP |
| `logout_time` | DATETIME |

#### `password_resets`
| Column | Type |
|---|---|
| `id` | INT UNSIGNED PK |
| `user_id` | INT UNSIGNED FK |
| `token` | VARCHAR(64) UNIQUE |
| `expires_at` | DATETIME |
| `used` | TINYINT(1) |
| `created_at` | TIMESTAMP |

#### `archives`
| Column | Type |
|---|---|
| `id` | INT UNSIGNED PK |
| `table_name` | VARCHAR(100) |
| `record_id` | INT UNSIGNED |
| `data_json` | LONGTEXT |
| `archived_by` | INT UNSIGNED FK |
| `archived_at` | TIMESTAMP |

#### `user_requests`
| Column | Type |
|---|---|
| `id` | INT UNSIGNED PK |
| `requester_id` | INT UNSIGNED FK |
| `barangay_id` | INT UNSIGNED |
| `request_type` | ENUM (6 types) |
| `subject` | VARCHAR(200) |
| `details` | TEXT |
| `status` | ENUM (pending/viewed/processing/approved/rejected/done/archived) |
| `admin_notes` | TEXT |
| `reviewed_by` | INT UNSIGNED FK |
| `reviewed_at` | TIMESTAMP |
| `created_at` | TIMESTAMP |

#### `notices`
| Column | Type |
|---|---|
| `id` | INT UNSIGNED PK |
| `title` | VARCHAR(200) |
| `content` | TEXT |
| `file_path` | VARCHAR(255) |
| `status` | ENUM('Active','Expired','Archived') |
| `created_by` | INT UNSIGNED FK |
| `created_at` | TIMESTAMP |

#### `notice_dismissals`
| Column | Type |
|---|---|
| `id` | INT UNSIGNED PK |
| `notice_id` | INT UNSIGNED FK |
| `user_id` | INT UNSIGNED FK |
| `dismissed_at` | TIMESTAMP |
| UNIQUE | `(notice_id, user_id)` |

#### `system_settings`
| Column | Type |
|---|---|
| `setting_key` | VARCHAR(100) UNIQUE |
| `setting_val` | TEXT |
| `updated_by` | INT UNSIGNED |
| `updated_at` | TIMESTAMP |

#### `landing_content`
| Column | Type |
|---|---|
| `id` | INT UNSIGNED PK (always row 1) |
| `welcome_title` | VARCHAR(200) |
| `welcome_subtitle` | VARCHAR(200) |
| `welcome_desc` | TEXT |
| `about_text` | TEXT |
| `contact_phone/email/address` | VARCHAR |
| `logo1_path`, `logo2_path`, `banner_path` | VARCHAR(255) |
| `updated_at` | TIMESTAMP |

#### `blotter_witnesses`
`blotter_id`, `name`, `address`, `contact`

#### `blotter_attachments`
`blotter_id`, `file_name`, `file_path`, `file_type`, `description`, `uploaded_by`, `uploaded_at`

#### `blotter_persons_involved`
`blotter_id`, `resident_id` (nullable FK), `full_name`, `address`, `contact`, `gender`, `age`, `role`

#### `blotter_timeline`
`blotter_id`, `event`, `status`, `actor`, `note`, `created_at`

#### `addresses`
`region`, `province`, `city`, `barangay`, `street`, `purok`, `zone`, `zip_code`

#### `household_members`
`household_id`, `resident_id`, `relationship`

#### `reports`
`barangay_id`, `report_type`, `month`, `year`, `notes`, `created_at`

---

## 16. Security Model

### Authentication
- Passwords: **bcrypt, cost 12** via `password_hash()` / `password_verify()`
- Session: `session_regenerate_id(true)` on login and logout
- Session cookies: `httponly=1`, `strict_mode=1`, `secure=1` on HTTPS, `SameSite=None` (HTTPS) or `Lax` (HTTP)

### CSRF Protection
Every POST handler calls `verifyCsrfOrAbort()`:

```php
// Token generated per session, rotated after each successful POST
$_SESSION['csrf_token'] = bin2hex(random_bytes(32));

// Check: POST field OR X-CSRF-Token header (for fetch/AJAX)
hash_equals($sessionToken, $submitted)  // timing-safe comparison

// Rotation: new token generated after each successful verification
$_SESSION['csrf_token'] = bin2hex(random_bytes(32));
```

All pages include `<meta name="csrf-token">` and `<?= csrfField() ?>` in forms.

### Input Sanitization
```php
sanitize($input)  // htmlspecialchars + strip_tags + trim
```

All database values use **PDO prepared statements with bound parameters**. No raw string interpolation of user input in SQL.

### File Upload Validation
- Extension whitelist check
- **MIME type validation via `finfo_file()`** (not just extension)
- Size limits enforced per upload type

### Reset Token Security
- Token: `bin2hex(random_bytes(32))` → 64 hexadecimal chars
- Expiry: 30 minutes (user-facing) / 24 hours (welcome email)
- Single-use: marked `used=1` after consumption
- Enumeration prevention: forgot-password always returns success

### Role Enforcement
Every page top:
```php
requireRole('barangay_admin');  // or 'super_admin'
```

Every query scoped by `barangay_id`:
```php
WHERE barangay_id = ?  // bound parameter, never interpolated
```

### Idle Timeout (Client-Side)
JavaScript overlay with configurable countdown (default 5 min) triggers `fetch('/logout.php')` and shows a re-login modal. Settings read from `system_settings` on every page load via `includes/topbar.php`.

### ngrok Compatibility
- `X-Forwarded-Host` and `X-Forwarded-Proto` headers respected for `BASE_URL` detection
- `ngrok-skip-browser-warning: true` header sent on every response

---

## 17. UI & Frontend Features

### Dark Mode
- Toggle in user dropdown (topbar)
- Persisted in `localStorage.darkMode`
- CSS class `dark-mode` on `<html>` element
- Patches inline `onmouseover` table row colors to dark palette when active

### Font Size Control
- +/− buttons in user dropdown
- 6 steps: 80%, 90%, 100%, 110%, 120%, 130%
- Applied to `document.documentElement.style.fontSize`
- Persisted in `localStorage.fontSize`

### Notification Bell (Topbar)
- Loads unread count + last 50 messages on every authenticated page load
- Badge: shows count, hides when 0, caps display at "9+"
- Mark All Read via fetch + `X-CSRF-Token`
- Animated bell pulse on new Pusher notification
- Browser native `Notification` API triggered if permission granted

### Responsive Sidebar
Collapsible on mobile via `toggleSidebar()`. Separate sidebar templates for each role.

### Search Auto-Submit
Fields with `data-search-form` attribute auto-submit their form after a debounce delay (no button press needed).

### Contact Validation
`validateContact()` JS helper enforces `09XXXXXXXXX` format. Shared across all pages via topbar.

### Date Range Presets
Available on blotter, audit log, reports, and other filtered views:
- Today
- Last Day
- This Week (7 days)
- Last 30 Days
- Last 90 Days
- This Year

### Flash Messages
Session-based one-time messages: `flash($key, $message, $type)` + `getFlash($key)`. Rendered as Bootstrap dismissible alerts on redirect.

### Profile Photos
Displayed in topbar user button. Cached in `$_SESSION['user_photo']` to avoid repeated DB queries.

---

## 18. File Structure

```
lgu_system/
├── .env                              ← All secrets and credentials
├── composer.json / composer.lock     ← PHP Composer dependencies
├── index.php                         ← Root redirect
├── landing.php                       ← Login, forgot password, reset, 2FA redirect
├── logout.php                        ← Session destroy + redirect
├── change_password.php               ← Standalone password change
├── setup_2fa.php                     ← Redirects to settings/authenticator
│
├── config/
│   ├── config.php                    ← Bootstrap: constants, .env, session, autoloads
│   └── database.php                  ← PDO singleton class
│
├── helpers/
│   ├── auth.php                      ← Role checks, loginUser(), logoutUser()
│   ├── functions.php                 ← CSRF, sanitize, logActivity(), sendNotification(), archiveRecord()
│   ├── mailer.php                    ← PHPMailer + all HTML email builders
│   ├── pusher.php                    ← Pusher SDK singleton + pushNotification()
│   └── totp.php                      ← RFC 6238 TOTP (generateSecret, getCode, verify, getQRCodeUrl)
│
├── includes/
│   ├── topbar.php                    ← Notification bell, Pusher JS, idle timeout, CSRF meta, UI features
│   ├── sidebar_barangay.php          ← Barangay admin navigation
│   ├── sidebar_super.php             ← Super admin navigation
│   └── date_range_picker.php         ← Shared date range picker UI
│
├── ajax/
│   ├── get_arima_forecast.php        ← PHP → Python forecasting proxy (with auto-start)
│   ├── get_certificate.php           ← Certificate data for print preview
│   ├── get_households.php            ← Household dropdown data
│   ├── get_household_members.php     ← Household member list
│   ├── get_resident.php              ← Resident form auto-fill
│   ├── get_resident (1).php          ← Alternate resident lookup
│   ├── get_resident_address.php      ← Resident address lookup
│   ├── get_landing_stats.php         ← PUBLIC: landing page live counters
│   ├── mark_notifications_read.php   ← Mark all notifications as read
│   ├── mark_notifications_read (1).php
│   ├── check_contact.php             ← Duplicate contact number check
│   └── pusher_auth.php               ← Pusher private channel authentication
│
├── barangay_admin/
│   ├── dashboard.php
│   ├── residents.php
│   ├── add_resident.php / edit_resident.php / view_resident.php
│   ├── households.php / add_household.php / edit_household.php
│   ├── blotter.php / export_blotter.php
│   ├── certificates.php / issue_certificate.php / print_certificate.php
│   ├── officials.php
│   ├── notices.php
│   ├── requests.php
│   ├── reports.php / export_residents.php / export_report.php
│   ├── archive.php
│   ├── activity_log.php / audit_log.php
│   ├── settings.php / settings_new.php
│   └── forecasting.php
│
├── super_admin/
│   ├── dashboard.php
│   ├── users.php
│   ├── barangays.php / export_barangays.php
│   ├── notices.php
│   ├── requests.php
│   ├── reports.php / export_report_lgu.php
│   ├── archive.php
│   ├── activity_log.php / audit_log.php
│   ├── settings.php
│   └── forecasting.php
│
├── python/
│   ├── arima_api.py                  ← Flask ARIMA microservice
│   ├── requirements.txt              ← Python dependencies
│   ├── start_api.bat                 ← Manual launcher
│   ├── run_api_hidden.vbs            ← Hidden launcher (no console)
│   ├── register_startup.bat          ← Register auto-start on boot
│   ├── install_deps.bat              ← pip install
│   └── README.md
│
├── assets/
│   ├── css/
│   │   ├── main.css                  ← Global styles (sidebar, topbar, dark mode, etc.)
│   │   ├── login.css                 ← Landing page login panel styles
│   │   └── landing.css              ← Landing page hero styles
│   ├── js/
│   │   └── landing.js               ← Landing page counter animations, login form JS
│   └── img/
│       ├── logo 1.jpg / logo 2.jpg   ← Default LGU logos
│       └── favcon.png               ← Favicon
│
├── database/
│   ├── lgu_system.sql               ← Base schema
│   ├── lgu_system_complete.sql      ← Full schema with all migrations
│   ├── migrate_*.sql                ← Individual migration files
│   └── SETUP_ARCHIVE_FEATURE.md
│
├── uploads/                         ← User-uploaded files (auto-created)
│   ├── residents/                   ← Resident photos
│   ├── profile_photos/              ← User profile photos
│   ├── blotter/                     ← Blotter case attachments
│   └── notices/                     ← Notice attachments
│
└── vendor/                          ← Composer packages (PHPMailer, Pusher SDK)
```

---

## 19. Environment Configuration (.env)

Located at project root. Loaded manually by `config/config.php` (no dotenv library required).

```env
# Database
DB_HOST=localhost
DB_PORT=3307
DB_NAME=lgu_system
DB_USER=root
DB_PASS=

# Email (SMTP)
MAIL_HOST=smtp.gmail.com
MAIL_PORT=587
MAIL_USERNAME=your-email@gmail.com
MAIL_PASSWORD=your-app-password
MAIL_FROM_EMAIL=your-email@gmail.com
MAIL_FROM_NAME=Talavera LGU Information Management System

# Public URL (for email links, use LAN IP or ngrok URL)
APP_URL=http://localhost/lgu_system

# Pusher (optional — all real-time features disable gracefully if missing)
PUSHER_APP_ID=
PUSHER_KEY=
PUSHER_SECRET=
PUSHER_CLUSTER=mt1
```

> **Security Note:** Never commit `.env` to version control. It contains database credentials and API secrets.

---

## 20. Email Templates Reference

All email templates share the same visual brand: blue gradient header, white body, info boxes with blue borders, yellow warning boxes, and a gray footer. Responsive via HTML table layout for maximum email client compatibility.

### Password Reset Email
- **Header:** 🔐 "Password Reset Request"
- **Body:** Greeting, explanation, expiry notice (30 min), CTA button "Reset My Password", fallback URL, warning box
- **Expires:** 30 minutes

### Welcome Email (New User)
- **Header:** 🏛️ "Welcome to {APP_NAME}"
- **Body:** User's role, username, "Set My Password" CTA button, expiry notice (24 hours), security warning
- **Expires:** 24 hours

### Password Changed by Admin Email
- **Header:** 🔐 "Password Changed"
- **Body:** Notification that admin reset the password, temporary credentials box (username + temp password in `<code>`), "Login Now" CTA

### Blotter Filed Email (to Super Admins)
- **Header:** ⚠️ "New Blotter Case Filed"
- **Body:** Case number, title, incident type, priority badge, location, incident date, barangay name, filed by

### Notice Broadcast Email (to Barangay Admins)
- **Header:** 📢 "New Official Notice"
- **Body:** Notice title, content, posted by, date, CTA to view in system

### Certificate Issued Email (to Issuing Admin)
- **Header:** ✅ "Certificate Issued"
- **Body:** Certificate type, resident name, purpose, date issued

### Request Status Update Email (to Requester)
- **Header:** Dynamic based on status (✅ approved / ❌ rejected / ⚙️ processing / 🎉 done)
- **Body:** Request subject, new status, admin notes (if any), CTA to view in system

### Resident Added Email (to Adding Admin)
- **Header:** 👤 "New Resident Added"
- **Body:** Resident name, barangay, date added

---

*End of Documentation*

---

> **Document maintained by:** System Administrator  
> **Last updated:** September 4, 2026  
> **Version:** 1.0.0
