# Tailscale Integration — Centralized LGU System

This document explains how Tailscale is integrated into the LGU system, how the security model works, and how HTTPS is achieved without a traditional SSL setup on Apache.

---

## What is Tailscale?

Tailscale is a VPN service built on top of **WireGuard**. It creates a secure, private mesh network between devices — no port forwarding, no public IP, no traditional VPN server required.

Each device on the Tailscale network gets:
- A **Tailscale IP** (100.x.x.x range)
- A **MagicDNS hostname** (e.g., `lgu-server.tail434c74.ts.net`)

---

## How Tailscale is Used in This System

The LGU system runs on **XAMPP (Apache + PHP + MySQL)** on the host machine. Tailscale makes that machine accessible to other devices (barangay admins) over a secure private network — without exposing the server to the public internet.

### Network Architecture

```
[Barangay Admin Browser]
        │
        │  HTTPS (port 443)
        ▼
[Tailscale Network / Funnel]
        │
        │  Tailscale terminates SSL here
        │  Forwards as HTTP to localhost
        ▼
[Host Machine — Apache on port 80]
        │
        ▼
[lgu_system PHP App]
        │
        ▼
[MySQL Database — localhost only]
```

---

## Two Modes of Access

### Mode 1 — Tailscale VPN (Private, Same Tailnet Only)

Devices that are **joined to the same Tailscale network** can access the server directly via its Tailscale IP or MagicDNS hostname.

```
http://lgu-server.tail434c74.ts.net/lgu_system/home.php
```

- Requires the accessing device to have Tailscale installed and connected
- Traffic is encrypted end-to-end via WireGuard
- No certificate needed — encryption is at the VPN layer

### Mode 2 — Tailscale Funnel (Public HTTPS Access)

**Tailscale Funnel** allows the server to be accessed from **any browser on the internet** — even without Tailscale installed on the client device.

```
https://lgu-server.tail434c74.ts.net/lgu_system/home.php
```

Funnel is enabled with:
```powershell
tailscale funnel 80
```

---

## How HTTPS Works (Security Model)

This is the key part. Apache itself runs on **plain HTTP (port 80)**. There is **no SSL certificate configured on Apache**. HTTPS is handled entirely by Tailscale Funnel.

### SSL/TLS Termination Flow

```
Browser
  │
  │  HTTPS request (port 443)
  │  Encrypted with TLS (Tailscale-managed certificate)
  ▼
Tailscale Funnel Edge Node
  │
  │  Tailscale decrypts the TLS here
  │  Forwards as plain HTTP to 127.0.0.1:80
  │  Adds header: X-Forwarded-Proto: https
  ▼
Apache (port 80, localhost)
  │
  ▼
PHP App
```

### The Certificate

Tailscale automatically provisions and renews a **valid TLS certificate** for the MagicDNS hostname using **Let's Encrypt** via its ACME integration. This means:

- The browser sees a valid, trusted HTTPS connection (green padlock)
- No self-signed certificate warnings
- No manual certificate renewal
- Certificate is tied to `lgu-server.tail434c74.ts.net`

To regenerate the cert manually:
```powershell
tailscale cert lgu-server.tail434c74.ts.net
```

---

## How the PHP App Detects HTTPS

Since Apache only sees HTTP from Tailscale's forwarding, the app uses two signals to detect that the connection is actually secure:

```php
// config/config.php

$scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
          || ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https'
          || str_ends_with($_SERVER['HTTP_HOST'] ?? '', '.ts.net')
          ? 'https' : 'http';
```

| Signal | Source | Meaning |
|--------|--------|---------|
| `$_SERVER['HTTPS']` | Apache | Direct HTTPS on Apache (not used with Funnel) |
| `HTTP_X_FORWARDED_PROTO` | Tailscale Funnel header | Funnel is passing HTTPS traffic |
| Host ends with `.ts.net` | MagicDNS hostname | Accessed via Tailscale network |

This ensures `BASE_URL` is always built with the correct scheme (`https://`) when accessed through Tailscale, so all asset links, redirects, and email links use the right protocol.

---

## Automatic Root Redirect

When a user visits the root URL `https://lgu-server.tail434c74.ts.net/`, Apache serves `C:\xampp\htdocs\index.php` which detects the `.ts.net` hostname and redirects to the app:

```php
// C:\xampp\htdocs\index.php
if (str_ends_with($host, '.ts.net')) {
    header('Location: /lgu_system/home.php', true, 302);
    exit;
}
```

So the full URL `https://lgu-server.tail434c74.ts.net/` automatically opens the LGU system login page.

---

## Current Configuration Summary

| Setting | Value |
|---------|-------|
| Tailscale hostname | `lgu-server.tail434c74.ts.net` |
| Tailscale Tailnet | `tail434c74.ts.net` |
| Funnel port | `80` (forwarded from public HTTPS 443) |
| Apache port | `80` (HTTP only, no SSL on Apache) |
| APP_URL | `https://lgu-server.tail434c74.ts.net` |
| SSL handled by | Tailscale Funnel (Let's Encrypt) |
| MySQL | `localhost` only (not exposed to network) |

---

## Security Notes

- **MySQL is never exposed** to the network — `bind-address = 127.0.0.1` in `my.ini`
- **Apache only listens on** `0.0.0.0:80` — no direct public internet exposure
- **All traffic** from the internet goes through Tailscale's encrypted tunnel first
- **Session cookies** are set with `Secure` and `SameSite=None` when HTTPS is detected
- **CSRF protection** is active on all forms
- **2FA (TOTP)** is available for the Super Admin account

---

## Firewall Requirements

| Port | Protocol | Purpose | Status |
|------|----------|---------|--------|
| 80 | TCP Inbound | Apache HTTP (Tailscale → Apache) | Must be allowed |
| 443 | TCP | Handled by Tailscale, not Apache | Not needed on Apache |
| 3307 | TCP | MySQL | Localhost only, no inbound rule needed |

To add the port 80 firewall rule (run as Administrator):
```powershell
New-NetFirewallRule -DisplayName "XAMPP Apache HTTP" -Direction Inbound -Protocol TCP -LocalPort 80 -Action Allow
```

---

## Tailscale Funnel Commands Reference

```powershell
# Enable Funnel on port 80
tailscale funnel 80

# Check Funnel status
tailscale funnel status

# Reset/disable Funnel
tailscale funnel reset

# Generate/renew TLS certificate
tailscale cert lgu-server.tail434c74.ts.net
```

---

## Access URLs

| Method | URL | Who Can Access |
|--------|-----|----------------|
| Tailscale Funnel (HTTPS) | `https://lgu-server.tail434c74.ts.net/lgu_system/home.php` | Anyone on the internet |
| Tailscale VPN (HTTP) | `http://lgu-server.tail434c74.ts.net/lgu_system/home.php` | Tailscale members only |
| Local network | `http://CentralizedLGU/lgu_system/home.php` | Same machine / LAN |
| Localhost | `http://localhost/lgu_system/home.php` | Host machine only |
