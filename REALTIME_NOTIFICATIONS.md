# Realtime Notification System — LGU System
## Talavera LGU Information Management System

> **Technology:** [Pusher](https://pusher.com/) Channels (hosted WebSocket service; cluster is configurable)
> **Scope:** Both roles — `super_admin` and `barangay_admin`
> **Last audited:** 2026-11-06

## Paliwanag sa Filipino — saan ginagamit at paano

Ang system ay gumagamit ng **Pusher Channels** bilang hosted WebSocket service. Hindi ito sariling WebSocket server: ang PHP app ang nagpapadala ng event sa Pusher gamit ang server-side credentials, at ang browser ay kumokonekta sa Pusher para makatanggap nito agad. Nananatili rin sa MySQL ang bawat notification, kaya makikita pa rin ito sa bell kapag hindi available o hindi naka-configure ang Pusher.

### Mga file at tungkulin

| File | Gamit |
|---|---|
| `config/config.php` | Binabasa ang Pusher settings mula sa `.env` at nilo-load ang Composer SDK. |
| `helpers/functions.php` | `sendNotification()` ang pangunahing function: nagsi-save muna sa `notifications`, saka tinatawag ang real-time push. |
| `helpers/pusher.php` | Gumagawa ng Pusher client at nagpapadala ng `new-notification` event sa private channel ng recipient. |
| `includes/topbar.php` | Naglo-load ng Pusher JavaScript SDK kapag kumpleto ang credentials; nag-a-update ng bell, listahan, toast, at optional browser notification. Kasama ito sa pages ng parehong admin role. |
| `ajax/pusher_auth.php` | Nagpapatunay na naka-login ang user at sarili lang niyang `private-user-{id}` channel ang puwede niyang salihan. |
| `ajax/mark_notifications_read.php` | Minamarkahang nabasa ang notifications ng kasalukuyang user; hiwalay ito sa pagtanggap ng real-time event. |
| `ajax/send_notice_notifications.php` | Isang halimbawa ng workflow na nagpapadala ng announcement notifications sa mga barangay admin. |

### Daloy ng notification

1. Tumatawag ang isang PHP action sa `sendNotification($userId, $message, $type, $category)`.
2. Ini-insert muna ang notification sa database para manatili ito kahit offline ang recipient.
3. Tinatawag ng `sendNotification()` ang `pushNotification()` sa `helpers/pusher.php`.
4. Ipinapadala ng PHP SDK ang `new-notification` event sa `private-user-{userId}` channel sa Pusher.
5. Ang browser ng recipient ay tumatanggap ng event sa `includes/topbar.php`; ina-update nito ang bell badge/list, nagpapakita ng toast at nagpa-pulse ng bell. Lalabas lang ang browser notification kung pinayagan ng user ang browser permission.

### Pag-configure

1. Gumawa o pumili ng Pusher Channels app at kunin ang App ID, Key, Secret, at Cluster mula sa Pusher dashboard.
2. Ilagay ang mga value sa `.env` sa root ng project. Huwag ilagay ang Secret sa JavaScript o i-commit ang `.env`.

   ```dotenv
   PUSHER_APP_ID=your_app_id
   PUSHER_KEY=your_public_key
   PUSHER_SECRET=your_secret
   PUSHER_CLUSTER=your_cluster
   ```

3. Dapat eksaktong tumugma ang `PUSHER_CLUSTER` sa cluster ng Pusher app. Ang default ng code kapag walang value ay `mt1`; palitan ito kung ibang cluster ang ipinakita ng dashboard.
4. Tiyaking naka-install ang Composer dependencies (`composer install`), kabilang ang `pusher/pusher-php-server`.
5. Mag-sign out at mag-sign in muli o i-reload ang app pagkatapos baguhin ang `.env`.

Kapag walang laman ang alinman sa App ID, Key, o Secret, hindi ilo-load ng topbar ang Pusher browser client at hindi magpapadala ng live event. Patuloy pa ring mase-save sa database ang notification at lalabas ito sa susunod na page load. Hindi kailangang magpatakbo ng sariling WebSocket server.

### Paano gamitin sa bagong PHP action

Pagkatapos makumpirma ang recipient, tawagin ang shared helper sa halip na mag-trigger ng Pusher mula mismo sa bawat page:

```php
sendNotification(
    $recipientId,
    'May bagong kahilingan na kailangan mong suriin.',
    'info',
    'transaction'
);
```

`$recipientId` ang user ID mula sa `users`; `type` ang visual style (`info`, `success`, `warning`, `danger`); at `category` ang uri (`system`, `resident`, `certificate`, `announcement`, `transaction`). Ang `sendNotification()` ang bahala sa database insert at Pusher push. Ipadala lang ang notification sa user o users na dapat makatanggap nito.

### Paano subukan

1. I-configure ang `.env` gamit ang aktuwal na credentials ng Pusher app.
2. Magbukas ng dalawang browser session: isa para sa nagpapadalang admin at isa para sa recipient.
3. Gawin ang action na tumatawag sa `sendNotification()` (halimbawa, magpadala ng notice mula sa Super Admin).
4. Dapat lumitaw agad ang notification sa recipient nang hindi nire-refresh ang page.
5. Kung hindi ito lumitaw, tingnan ang browser console/network para sa Pusher connection o authorization error, ang PHP error log para sa `[Pusher] trigger failed`, at tiyaking tugma ang App ID, Key, Secret, Cluster at naka-login ang recipient.

Hindi ipinapadala ang Pusher Secret sa browser. Ang private-channel authorization sa `ajax/pusher_auth.php` ay pumipigil sa user na mag-subscribe sa channel ng ibang user.

---

## Table of Contents

1. [Architecture Overview](#1-architecture-overview)
2. [Pusher Configuration](#2-pusher-configuration)
3. [Backend — How Notifications Are Sent](#3-backend--how-notifications-are-sent)
4. [Frontend — How Notifications Are Received](#4-frontend--how-notifications-are-received)
5. [Auth Endpoint](#5-auth-endpoint)
6. [AJAX Endpoints](#6-ajax-endpoints)
7. [Database Schema](#7-database-schema)
8. [Role Coverage Matrix](#8-role-coverage-matrix)
9. [Event Catalogue](#9-event-catalogue)
10. [Known Issues & Fixes](#10-known-issues--fixes)
11. [Quick-Fix SQL Patches](#11-quick-fix-sql-patches)
12. [Security Checklist](#12-security-checklist)

---

## 1. Architecture Overview

```
┌─────────────────────────────────────────────────────────┐
│                     PHP Action                          │
│  (residents.php, blotter.php, requests.php, etc.)       │
└────────────────────────┬────────────────────────────────┘
                         │ calls
                         ▼
┌─────────────────────────────────────────────────────────┐
│         helpers/functions.php → sendNotification()      │
│                                                         │
│  1. INSERT INTO notifications (user_id, message, …)     │
│  2. calls pushNotification($userId, $payload)           │
└────────────────────────┬────────────────────────────────┘
                         │ calls
                         ▼
┌─────────────────────────────────────────────────────────┐
│         helpers/pusher.php → pushNotification()         │
│                                                         │
│  $pusher->trigger(                                      │
│     "private-user-{$userId}",   // channel             │
│     "new-notification",         // event               │
│     [ id, message, type, … ]    // payload             │
│  );                                                     │
└────────────────────────┬────────────────────────────────┘
                         │ over HTTPS/TLS
                         ▼
                   ┌───────────┐
                   │  Pusher   │  (ap1 cluster)
                   └─────┬─────┘
                         │ WebSocket push
                         ▼
┌─────────────────────────────────────────────────────────┐
│         includes/topbar.php (browser)                   │
│                                                         │
│  channel.bind("new-notification", function(data) {      │
│     prependNotif(data);   // bell + badge + toast       │
│  });                                                    │
└─────────────────────────────────────────────────────────┘
```

Every authenticated page for both roles includes `includes/topbar.php`, which loads the Pusher JS SDK and subscribes to the current user's **private** channel. No page is excluded from the bell.

---

## 2. Pusher Configuration

### Environment Variables (`.env`)

| Variable | Purpose |
|---|---|
| `PUSHER_APP_ID` | Server-side app identifier |
| `PUSHER_KEY` | Public key (safe to embed in HTML) |
| `PUSHER_SECRET` | Server-side signing secret (**never expose to browser**) |
| `PUSHER_CLUSTER` | `ap1` (Southeast Asia) |

### PHP Constants (`config/config.php`)

```php
define('PUSHER_APP_ID',  $_ENV['PUSHER_APP_ID']  ?? '');
define('PUSHER_KEY',     $_ENV['PUSHER_KEY']     ?? '');
define('PUSHER_SECRET',  $_ENV['PUSHER_SECRET']  ?? '');
define('PUSHER_CLUSTER', $_ENV['PUSHER_CLUSTER'] ?? 'mt1');
```

The fallback cluster is `mt1`; set `PUSHER_CLUSTER` to the exact cluster shown in the Pusher dashboard if the app uses another cluster.

### SDK Instantiation (`helpers/pusher.php`)

```php
$pusher = new \Pusher\Pusher($key, $secret, $appId, [
    'cluster'      => $cluster,
    'useTLS'       => true,           // always encrypted
    'curl_options' => [
        CURLOPT_CONNECTTIMEOUT => 3,
        CURLOPT_TIMEOUT        => 5,  // won't block the main request
    ],
]);
```

Returns `null` gracefully if credentials are empty — all callers check for `null` before using the instance.

---

## 3. Backend — How Notifications Are Sent

### `sendNotification()` — `helpers/functions.php`

```php
sendNotification(
    int    $userId,
    string $message,
    string $type     = 'info',       // 'info'|'success'|'warning'|'danger'
    string $category = 'system'      // 'system'|'resident'|'transaction'|
                                     // 'certificate'|'announcement'
);
```

1. Inserts a row into `notifications` (with available actor metadata).
2. Gets the inserted row ID and timestamp.
3. Calls `pushNotification($userId, $notifId, $message, $type, $createdAt, $category, ...)`.

### Fan-out Helpers

| Helper | What it does |
|---|---|
| `notifyBarangayAdmins($barangayId, $msg, $type, $cat)` | Notifies all active `barangay_admin` users of the given barangay |
| `notifySuperAdmins($msg, $type, $cat)` | Notifies all active `super_admin` users |

---

## 4. Frontend — How Notifications Are Received

All frontend logic lives in `includes/topbar.php`.

### Pusher JS Constructor

```js
const pusher = new Pusher('<?= htmlspecialchars($pusherKey) ?>', {
    cluster:      '<?= htmlspecialchars($pusherCluster) ?>',
    authEndpoint: '<?= BASE_URL ?>/ajax/pusher_auth.php',
    auth: { headers: { 'X-Requested-With': 'XMLHttpRequest' } }
});
```

Wrapped in `if (!window._pusherNotifInitialized)` to prevent double-subscription if `topbar.php` is ever included twice on the same page.

### Channel & Event

```js
const channel = pusher.subscribe('private-user-<?= (int)$u['id'] ?>');

channel.bind('new-notification', function (data) {
    prependNotif(data);
});
```

- Channel: `private-user-{id}` — each user subscribes **only to their own channel**.
- Event: `new-notification`.

### `prependNotif(data)` — what it does

| Step | Detail |
|---|---|
| Deduplicate | Skips the notification if `data.id` was already rendered (prevents double-display on reconnect) |
| Insert HTML | Prepends a new `<li>` to `#notifList` |
| Badge | Increments `#notifBadge`, makes it visible |
| Bell flash | Adds `.bell-ring` CSS animation class to the bell button |
| Browser Notification | Fires `new Notification(title, { body, icon })` if permission was granted |
| Toast | Uses the `#notifToastContainer` for a slide-in toast message |

### Initial Load (Server-Side)

When the page first loads, `includes/topbar.php` calls two PHP functions to pre-populate the dropdown **before** any WebSocket messages arrive:

```php
$notifCount = countUnreadNotifications($u['id']);
$notifications = getAllNotifications($u['id'], 50);
```

These render the bell badge and up to 50 notifications synchronously in HTML.

---

## 5. Auth Endpoint

**File:** `ajax/pusher_auth.php`

```
POST /ajax/pusher_auth.php
Content-Type: application/x-www-form-urlencoded

socket_id=123.456&channel_name=private-user-7
```

**Server-side logic:**

1. `requireLogin()` — aborts with HTTP 401 if no active session.
2. Gets the current user via `currentUser()`.
3. Rejects any channel that is not `private-user-{session user's ID}` with HTTP 403.
4. Calls `$pusher->authorizeChannel($channelName, $socketId)` and echoes the signed token.

This means a logged-in user can **only** subscribe to their own channel — they cannot intercept another user's notifications.

---

## 6. AJAX Endpoints

### `POST /ajax/mark_notifications_read.php`

Marks **all** of the current user's notifications as read.

- Auth: session required + CSRF token (`X-CSRF-Token` header).
- SQL: `UPDATE notifications SET is_read=1 WHERE user_id=?`
- Response: `{ "success": true, "csrf_token": "newToken" }`
- Called automatically when the notification dropdown is opened and `unreadCount > 0`.

### `POST /ajax/send_notice_notifications.php`

Broadcasts a notice (LGU announcement) to all relevant `barangay_admin` users via Pusher + email.

- Auth: `requireRole('super_admin')` + CSRF.
- Input: `notice_id` (POST).
- Logic:
  - If `notices.target_barangay_id > 0`: sends to that barangay's admins only.
  - Otherwise: broadcasts to all active `barangay_admin` users.
  - Sends email via `sendNoticeBroadcastEmails()`.
  - Sends a delivery confirmation bell notification to the acting super admin.
- Response: `{ "success": true, "sent": N, "failed": N, "doneMsg": "..." }`

### `GET /ajax/get_notices_widget.php`

Returns the 3 most recent active notices for the barangay admin dashboard widget.

- Auth: `requireRole('barangay_admin')` only.
- **Note:** This queries the `notices` table, **not** the `notifications` table. It is the dashboard announcements widget, unrelated to the bell.
- Response: `{ "success": true, "notices": [ { id, title, excerpt, date }, … ] }`

---

## 7. Database Schema

### `notifications` Table

```sql
CREATE TABLE notifications (
    id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id         INT UNSIGNED NOT NULL,
    message         TEXT NOT NULL,
    type            ENUM('info','success','warning','danger') DEFAULT 'info',
    category        VARCHAR(50) NOT NULL DEFAULT 'system',   -- ⚠ missing from base DDL
    actor_email     VARCHAR(254) NULL,                       -- ⚠ missing from base DDL
    actor_name      VARCHAR(200) NULL,                       -- ⚠ missing from base DDL
    actor_barangay  VARCHAR(200) NULL,                       -- ⚠ missing from base DDL
    is_read         TINYINT(1) DEFAULT 0,
    created_at      TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    INDEX idx_notifications_category (user_id, category, is_read)
) ENGINE=InnoDB;
```

> ⚠️ The columns marked "missing from base DDL" exist only in migration files / are added at runtime. See [§11](#11-quick-fix-sql-patches) for the fix.

### Pusher Payload (what `prependNotif` receives)

```json
{
    "id":            42,
    "message":       "New resident added: Juan Dela Cruz",
    "type":          "success",
    "category":      "resident",
    "actor_email":   "admin@brgy.gov.ph",
    "actor_name":    "Maria Santos",
    "actor_barangay":"Barangay 1",
    "created_at":    "2026-11-03 10:45:00"
}
```

### `notices` Table (Announcements — separate from bell)

```sql
CREATE TABLE notices (
    id                   INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    title                VARCHAR(200) NOT NULL,
    content              TEXT NOT NULL,
    file_path            VARCHAR(255),
    notice_type          VARCHAR(100) DEFAULT 'General',     -- ⚠ missing from base DDL
    target_barangay_id   INT UNSIGNED NULL,                  -- ⚠ missing from base DDL
    status               ENUM('Active','Expired','Archived') DEFAULT 'Active',
    expiry_date          DATE,
    created_by           INT UNSIGNED,
    created_at           TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB;
```

---

## 8. Role Coverage Matrix

| Role | Pages with topbar | Bell & Pusher | Receives bell for… |
|---|---|---|---|
| `super_admin` | All pages under `/super_admin/` | ✅ Yes | User account events, request approvals/rejections, transfer decisions, notice delivery confirmations, barangay settings changes |
| `barangay_admin` | All pages under `/barangay_admin/` | ✅ Yes | Resident changes, certificate issuances, transfer outcomes, request status updates, notice broadcasts, peer admin actions |

**Export/download handlers** (`export_*.php`) intentionally exclude the topbar — they stream binary data and have no UI.

---

## 9. Event Catalogue

All events use a single Pusher channel (`private-user-{id}`) and a single event name (`new-notification`). The `category` field in the payload differentiates notification types.

| Trigger | Category | Who receives the bell |
|---|---|---|
| User account created / password reset | `system` | Affected user + acting super admin |
| Resident added / edited / deceased | `resident` | Acting admin + barangay peers |
| Resident transfer submitted | `transaction` | All super admins |
| Resident transfer approved/rejected | `transaction` | Origin admin, destination admins, super admins |
| Certificate issued | `certificate` | Acting admin + barangay peers |
| Blotter case filed/updated | `transaction` | Acting admin + barangay peers (**not** super admins — see Issue 3) |
| Request submitted by barangay | `transaction` | All super admins |
| Request status changed by super admin | `transaction` | Requesting barangay admin |
| Notice broadcast | `announcement` | All active `barangay_admin` users (or targeted barangay) |
| Notice delivery confirmation | `announcement` | Acting super admin |
| Barangay logo/settings changed | `system` | All super admins |

---

## 10. Known Issues & Fixes

### ✅ Issue 1 — `notifications` Base Schema Stale (Fixed)

**Problem:** `database/lgu_system.sql` is missing `category`, `actor_email`, `actor_name`, `actor_barangay`. On a fresh install these are added at runtime via ALTER, which is fragile.

**Fix:** Apply the SQL patch in §11.

---

### ✅ Issue 2 — `notices` Table Missing `notice_type` & `target_barangay_id` (Fixed)

**Problem:** `send_notice_notifications.php` reads `$notice['notice_type']` and `$notice['target_barangay_id']`. On a fresh install these columns don't exist, producing warnings and broken notification messages like `"📢 New : Title"`.

**Fix:** Apply the SQL patch in §11.

---

### ✅ Issue 3 — Blotter Cases Don't Bell Super Admins (Fixed)

**Problem:** `barangay_admin/blotter.php` calls `sendBlotterFiledEmail()` for super admins but **not** `notifySuperAdmins()`. Super admins miss in-system bell rings for new blotter cases.

**Fix:** In `barangay_admin/blotter.php`, after the existing `notifyBarangayAdmins()` call (around line 253), add:

```php
notifySuperAdmins(
    "New blotter case filed in {$barangayName}: {$caseTitle}",
    'warning',
    'transaction'
);
```

---

### ✅ Issue 4 — `settings.php` Includes `topbar.php` Twice (Fixed)

**Problem:** `barangay_admin/settings.php` has two `include __DIR__ . '/../includes/topbar.php'` calls. This doubles the DB queries (`countUnreadNotifications` + `getAllNotifications` run twice). The Pusher subscription is guarded and won't double-subscribe, but the DB overhead is wasted.

**Fix:** Remove the duplicate include. Keep only the first occurrence at the top of the file.

---

### 🟡 Issue 5 — No Per-Item Mark-as-Read

**Problem:** Opening the dropdown marks **all** notifications as read instantly, even if the user only glances. There is no way to mark individual items.

**Fix (optional UX improvement):** Add a `?id=N` path to `mark_notifications_read.php` and change the auto-mark-on-open behaviour to explicit (button only), or add per-item read buttons.

---

### ✅ Issue 6 — `pusher_auth.php` Doesn't Validate `socket_id` Format (Fixed)

**Problem:** `$_POST['socket_id']` is passed directly to `$pusher->authorizeChannel()` without format checking. Risk is low (Pusher SDK rejects bad values), but it's best practice to validate.

**Fix:** Add before the authorize call:

```php
if (!preg_match('/^\d+\.\d+$/', $_POST['socket_id'] ?? '')) {
    http_response_code(400);
    echo json_encode(['error' => 'Invalid socket_id']);
    exit;
}
```

---

### ✅ Issue 7 — Toast Wiring Needs Verification (Fixed)

**Problem:** `topbar.php` includes a styled `#notifToastContainer` div and CSS for `.notif-toast` animations. The `prependNotif()` function should call `showToast()` for incoming realtime notifications, but this wiring should be verified in the full file.

**Fix:** Open `includes/topbar.php` and confirm `showToast(data.message, data.type)` is called inside the `prependNotif()` function body. If not, add it.

---

## 11. Quick-Fix SQL Patches

Run these on the production database (and add them to `database/lgu_system.sql`) to make the schema match the code:

```sql
-- Fix 1: notifications table missing columns
ALTER TABLE notifications
    ADD COLUMN IF NOT EXISTS category       VARCHAR(50)  NOT NULL DEFAULT 'system' AFTER type,
    ADD COLUMN IF NOT EXISTS actor_email    VARCHAR(254) NULL     AFTER category,
    ADD COLUMN IF NOT EXISTS actor_name     VARCHAR(200) NULL     AFTER actor_email,
    ADD COLUMN IF NOT EXISTS actor_barangay VARCHAR(200) NULL     AFTER actor_name;

-- Optional: add the performance index if not already present
CREATE INDEX IF NOT EXISTS idx_notifications_category
    ON notifications (user_id, category, is_read);

-- Fix 2: notices table missing columns
ALTER TABLE notices
    ADD COLUMN IF NOT EXISTS notice_type         VARCHAR(100) NOT NULL DEFAULT 'General' AFTER content,
    ADD COLUMN IF NOT EXISTS target_barangay_id  INT UNSIGNED NULL AFTER notice_type;
```

---

## 12. Security Checklist

| Item | Status | Action |
|---|---|---|
| `.env` in `.gitignore` | Verify | Run `git check-ignore -v .env` |
| Apache blocks direct `.env` access | Verify | Check `.htaccess` for `<Files ".env"> Deny from all </Files>` |
| Pusher auth validates channel ownership | ✅ Done | `private-user-{session ID}` check in `pusher_auth.php` |
| Pusher uses TLS | ✅ Done | `useTLS: true` in `helpers/pusher.php` |
| Auth endpoint requires login | ✅ Done | `requireLogin()` in `pusher_auth.php` |
| CSRF on mark-read endpoint | ✅ Done | `verifyCsrfOrAbort()` in `mark_notifications_read.php` |
| CSRF on send-notice endpoint | ✅ Done | `verifyCsrfOrAbort()` in `send_notice_notifications.php` |
| Pusher secret never sent to browser | ✅ Done | Only `PUSHER_KEY` is echoed into topbar JS |
| `socket_id` format validation | ✅ Done | Added `preg_match('/^\d+\.\d+$/')` guard in `pusher_auth.php` |
| Rotate Pusher secret & SMTP password periodically | Recommended | — |
