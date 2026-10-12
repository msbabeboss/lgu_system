# Setup Instructions for XAMPP

## Requirements
- XAMPP (PHP 8.0+, MySQL 5.7+)
- Browser (Chrome/Firefox recommended)

## Installation Steps

### 1. Copy Files
Copy the entire `lgu_system` folder to:
```
C:\xampp\htdocs\lgu_system
```

### 2. Import Database
1. Open XAMPP Control Panel → Start Apache & MySQL
2. Open browser → go to `http://localhost/phpmyadmin`
3. Click "New" → create database named `lgu_system`
4. Click "Import" → select `lgu_system/database/lgu_system.sql`
5. Click "Go"

### 3. Configure Database (if needed)
Edit `lgu_system/config/database.php`:
```php
private string $host   = 'localhost';
private string $dbname = 'lgu_system';
private string $user   = 'root';
private string $pass   = '';  // your MySQL password
```

### 4. Set Upload Permissions
Make sure the `uploads/` folder is writable.

### 5. Access the System
Open browser → `http://localhost/lgu_system`

## Default Login Credentials

| Role | Username | Password |
|------|----------|----------|
| Super Admin | `superadmin` | `password` |
| Barangay Admin | `brgy_admin1` | `password` |

> **Note:** The default password is `password`. You will be prompted to change it on first login.

## System Structure
```
lgu_system/
├── config/          # Database & app config
├── helpers/         # Auth & utility functions
├── includes/        # Sidebar & topbar components
├── assets/css/      # Stylesheets
├── super_admin/     # Super Admin pages
├── barangay_admin/  # Barangay Admin pages
├── ajax/            # AJAX endpoints
├── uploads/         # File uploads
├── database/        # SQL schema
├── index.php        # Login page
├── logout.php       # Logout
└── change_password.php
```

## Features Summary
- Role-based login (Super Admin / Barangay Admin)
- Super Admin: Dashboard with charts, Barangay management, User management, Notices, Reports approval, Activity log, Archive
- Barangay Admin: Residents CRUD, Households, Officials, Certificates (printable), Blotter with witnesses, Reports submission, Activity log, Archive with restore
- All data is barangay-isolated (admins only see their own data)
- Password hashing with bcrypt
- SQL injection prevention via PDO prepared statements
- Activity logging for all actions
- Notification system
- Print-ready certificates and blotter reports

---

# Centralized LGU System Setup (Multi-Barangay / Network)

Para sa deployment na accessible ng lahat ng barangay sa iisang server, gamitin ang setup na ito.

## Architecture Overview

```
[LGU Office — Host Machine]
  └── XAMPP (Apache + MySQL)
       └── lgu_system (PHP app)
            └── exposed via Tailscale VPN

[Barangay Admin 1]  ──┐
[Barangay Admin 2]  ──┤── Tailscale VPN ──► http://<tailscale-ip>/lgu_system
[Barangay Admin N]  ──┘
```

- **Host:** Ang LGU office computer ang magho-host ng XAMPP server
- **Network:** Tailscale ang nagbibigay ng secure private tunnel — hindi kailangan ng public IP o port forwarding
- **Access:** Bawat barangay admin ay naglo-login sa browser gamit ang Tailscale IP ng host

---

## Step 1 — Install Tailscale

### Sa Host Machine (LGU Office)
1. Pumunta sa [https://tailscale.com/download](https://tailscale.com/download)
2. I-download at i-install ang Tailscale para sa Windows
3. Mag-login gamit ang Google, Microsoft, o GitHub account
4. Makikita ang **Tailscale IP** ng host sa system tray (halimbawa: `100.x.x.x`)

### Sa Bawat Barangay Admin Machine
1. I-install din ang Tailscale
2. Mag-login sa **parehong Tailscale account** (o i-invite sa Tailscale admin console)
3. Kapag naka-connect na, maa-access na nila ang host gamit ang Tailscale IP nito

> Libre ang Tailscale para sa hanggang 100 devices.

---

## Step 2 — Configure XAMPP to Accept Network Connections

By default, ang Apache ay nakikinig lang sa `localhost`. Kailangan itong baguhin.

### Buksan ang `httpd.conf`
```
C:\xampp\apache\conf\httpd.conf
```

Hanapin ang linyang ito:
```
Listen 80
```
Palitan ng:
```
Listen 0.0.0.0:80
```

### Buksan ang `httpd-vhosts.conf` (optional, for virtual host)
```
C:\xampp\apache\conf\extra\httpd-vhosts.conf
```

I-add ang:
```apache
<VirtualHost *:80>
    DocumentRoot "C:/xampp/htdocs"
    ServerName lgu.local
    <Directory "C:/xampp/htdocs">
        Options Indexes FollowSymLinks
        AllowOverride All
        Require all granted
    </Directory>
</VirtualHost>
```

### I-restart ang Apache sa XAMPP Control Panel

---

## Step 3 — Restrict MySQL Access (Security)

I-edit ang `C:\xampp\mysql\bin\my.ini`, hanapin ang `[mysqld]` section, i-add:

```ini
bind-address = 127.0.0.1
```

Ibig sabihin: MySQL ay accessible lang locally. Ang PHP app (nasa parehong machine) ang mag-a-access ng DB — **hindi direkta ang mga kliyente**. ✅

---

## Step 4 — Update App Config for Network Access

I-edit ang `config/database.php`:

```php
private string $host   = '127.0.0.1';   // localhost lang, PHP at MySQL nasa iisang machine
private string $dbname = 'lgu_system';
private string $user   = 'root';
private string $pass   = 'YOUR_STRONG_PASSWORD';  // palitan ang default na blankong password
```

I-edit ang `config/config.php`, tiyakin na tama ang `BASE_URL`:

```php
define('BASE_URL', 'http://100.x.x.x/lgu_system');  // ilagay ang Tailscale IP ng host
```

> **Tip:** Para hindi palaging magbago ang Tailscale IP, maaaring gumamit ng [Tailscale MagicDNS](https://tailscale.com/kb/1081/magicdns) — bibigyan ng hostname ang bawat device (e.g., `http://lgu-office/lgu_system`).

---

## Step 5 — Role & Permission Reminders

Ang sistema ay may built-in na role isolation:

| Role | Access |
|------|--------|
| `super_admin` | Lahat ng barangay data, user management, reports approval |
| `barangay_admin` | Sariling barangay data lang (`barangay_id` filter sa lahat ng query) |

### Mga dapat gawin bago i-deploy:
- [ ] Palitan ang default na passwords ng lahat ng accounts
- [ ] Mag-set ng matibay na MySQL `root` password
- [ ] Mag-enable ng 2FA (available na sa sistema via `setup_2fa.php`)
- [ ] I-review ang mga barangay accounts sa Super Admin → Users

---

## Step 6 — Automated Database Backup

### Option A — Windows Task Scheduler (Recommended)

Gumawa ng batch file na `backup_db.bat`:

```bat
@echo off
SET BACKUP_DIR=C:\xampp\backups
SET DATE_STR=%date:~10,4%-%date:~4,2%-%date:~7,2%
SET FILENAME=%BACKUP_DIR%\lgu_system_%DATE_STR%.sql

IF NOT EXIST %BACKUP_DIR% MKDIR %BACKUP_DIR%

"C:\xampp\mysql\bin\mysqldump.exe" -u root -pYOUR_PASSWORD lgu_system > %FILENAME%

echo Backup done: %FILENAME%
```

> Palitan ang `YOUR_PASSWORD` ng tunay na MySQL password. Kung walang password, alisin ang `-pYOUR_PASSWORD`.

**I-schedule:**
1. Buksan ang **Task Scheduler** (Windows search)
2. Create Basic Task → ibigay ng pangalan (e.g., "LGU DB Backup")
3. Trigger: **Daily**, 11:00 PM
4. Action: Start a program → piliin ang `backup_db.bat`
5. Finish

### Option B — PHP-based Backup (accessible sa Super Admin)

Maaaring dagdagan ang Super Admin ng backup button na nagta-trigger ng `mysqldump` via `exec()` — pero ang Option A ay mas maaasahan.

### Backup Retention (Manual Cleanup)

Para awtomatikong matanggal ang mga backup na mas matanda sa 30 araw, idagdag sa `backup_db.bat`:

```bat
forfiles /p %BACKUP_DIR% /s /m *.sql /d -30 /c "cmd /c del @path"
```

---

## Step 7 — Windows Firewall (Allow Port 80)

Para makapasok ang Tailscale traffic sa Apache:

1. Buksan ang **Windows Defender Firewall** → Advanced Settings
2. Inbound Rules → New Rule
3. Port → TCP → 80
4. Allow the connection
5. Lagyan ng pangalan: "Apache HTTP (LGU System)"

---

## Accessing the System (Client Side)

Kapag naka-setup na ang lahat, ang bawat barangay admin ay:

1. I-install ang Tailscale at mag-join sa network
2. Buksan ang browser
3. Pumunta sa:
   ```
   http://<tailscale-ip-ng-host>/lgu_system
   ```
   o kung naka-MagicDNS:
   ```
   http://lgu-office/lgu_system
   ```
4. Mag-login gamit ang kanilang assigned na credentials

---

## Quick Checklist

- [ ] Tailscale installed at naka-connect ang lahat ng machines
- [ ] Apache naka-configure na sumasagot sa `0.0.0.0:80`
- [ ] Firewall rule para sa port 80 na naka-allow
- [ ] `BASE_URL` sa `config.php` updated sa Tailscale IP
- [ ] MySQL password hindi na blangko
- [ ] Lahat ng default passwords napalitan
- [ ] 2FA enabled para sa Super Admin account
- [ ] Automated backup naka-schedule sa Task Scheduler
- [ ] Backup folder (`C:\xampp\backups`) ay regularly ino-offload sa external drive o cloud
