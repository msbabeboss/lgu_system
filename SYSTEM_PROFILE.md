# System Profile — Centralized LGU Information Management System

---

## II. Part A — Capstone Test Profile

| Information | Details |
|-------------|---------|
| **Capstone Title** | Development of a Centralized Local Government Unit Information Management System |
| **System Type** | Web-Based Information Management System |
| **Development Platform/Technology** | PHP 8.0, Apache 2.4 (XAMPP), HTML5, CSS3, Bootstrap 5, JavaScript, Tailscale VPN |
| **Database Used** | MySQL / MariaDB 10.4.32 (via XAMPP), accessed using PDO (PHP Data Objects) |
| **Intended Users** | Super Admin (LGU Office Staff), Barangay Administrators (one per barangay) |
| **Main Purpose of System** | To centralize and digitize the management of barangay-level resident records, household data, certificates, blotter reports, and officials across multiple barangays under one LGU, accessible over a secure network via Tailscale VPN |
| **Major System Modules** | 1. Resident Management (CRUD, transfer, archive) · 2. Household Management · 3. Barangay Officials Management · 4. Certificate Issuance & Printing · 5. Blotter / Incident Reports · 6. User & Access Management · 7. Reports & Analytics (with ARIMA forecasting) · 8. Notifications & Activity Logs · 9. Dashboard (Super Admin & Barangay Admin) · 10. Archive & Records Management |
| **Number of Expected Users** | Approximately 50 concurrent users (1 Super Admin + multiple Barangay Administrators across all registered barangays) |
| **Testing Environment** | Local server running XAMPP on Windows, accessed via Tailscale Funnel (HTTPS) using Apache on port 80 forwarded through Tailscale at `https://lgu-server.tail434c74.ts.net`. Stress testing performed using Apache JMeter 5.6.3 |
| **Version / Build Tested** | PHP 8.0.30 · Apache 2.4.58 · MySQL/MariaDB 10.4.32 · JMeter 5.6.3 · System Version 1.0.0 |

---

## III. Part B — Stress Test Plan

**Simulated Activity:** Concurrent web users performing login, dashboard access, and resident record browsing — representing the primary daily workflow of barangay administrators.

| Test ID | Load Level | Number of Users/Requests | Duration | Expected Result |
|---------|-----------|--------------------------|----------|-----------------|
| ST-01 | Normal | 5 users | ~30 sec | System responds normally, no errors |
| ST-02 | Moderate | 20 users | ~60 sec | Slight increase in response time, no errors |
| ST-03 | High | 40 users | ~90 sec | Noticeable slowdown, system still functional |
| ST-04 | Very High | 75 users | ~120 sec | Significant load, possible delays |
| ST-05 | Peak / Stress | 100 users | ~150 sec | System at maximum capacity, possible degradation |

---

## IV. Part C — Stress Test Execution Results

> Tests were executed using Apache JMeter 5.6.3 targeting `https://lgu-server.tail434c74.ts.net`.
> Each test level simulated concurrent users performing: GET Home Page → GET CSRF Token → POST Login → GET Dashboard → GET Residents Page.
> CPU and Memory were captured using PowerShell (`Get-CimInstance Win32_Processor` / `Win32_OperatingSystem`) during active test execution.

| Metric | Normal (5) | Moderate (20) | High (40) | Very High (75) | Stress (100) |
|--------|:----------:|:-------------:|:---------:|:--------------:|:------------:|
| Number of Users/Requests | 5 | 20 | 40 | 75 | 100 |
| Average Response Time (ms) | 39 | 42 | 278 | 49 | 169 |
| Maximum Response Time (ms) | 125 | 152 | 2,819 | 202 | 1,068 |
| Error Count | 0 | 0 | 0 | 0 | 0 |
| Error Rate (%) | 0.00% | 0.00% | 0.00% | 0.00% | 0.00% |
| CPU Utilization (%) | 60% | 88% | 100% | 100% | 100% |
| Memory Utilization (%) | 81% | 84% | 87% | 83% | 85% |
| Requests/Transactions Completed | 125 | 300 | 600 | 750 | 1,000 |
| System Availability (%) | 100% | 100% | 100% | 100% | 100% |

---

## V. Part D — Stress Test Observations

### 1. Normal Load
**Observation:**
Under normal conditions with 5 concurrent users, the system performed efficiently. The average response time was 39ms with a maximum of 125ms. CPU utilization was at 60% and memory at 81%. All 125 requests completed successfully with 0 errors. The system was stable and responsive, consistent with its expected performance for daily barangay admin use.

---

### 2. Increasing Load
**Observation:**
As the number of users increased from 5 to 20, response time remained relatively stable (42ms average), though CPU utilization jumped significantly from 60% to 88%, indicating that the server was working harder even with only moderate load. At 40 users (High load), a major performance spike occurred — average response time increased to 278ms and maximum response time reached 2,819ms, with CPU hitting 100%. At 75 and 100 users, response times improved slightly (49ms and 169ms respectively) due to the longer ramp-up period (20–30 seconds) distributing the load more gradually, but CPU remained at 100% throughout both levels.

---

### 3. Peak Load
**Observation:**
The system began to slow down significantly at **40 concurrent users (ST-03 — High Load)**, where the maximum response time spiked to 2,819ms — approximately 22 times higher than the normal load maximum. This is the identified stress threshold for the current hardware configuration. Despite the slowdown, the system produced **0 errors** at all load levels, meaning it degraded gracefully without crashing or returning failed responses. The system remained technically available (100% availability) throughout all five test levels.

---

### 4. Recovery
**Observation:**
After each stress test level was stopped, the system returned to normal operation promptly. Response times dropped back to baseline levels, CPU utilization decreased from 100% to idle levels, and no crashes, data corruption, or service interruptions were observed. The XAMPP Apache and MySQL services remained stable after each run. The system demonstrated good recovery behavior after load was removed, with no manual intervention required.

---

### 5. Bottleneck

**Primary Bottleneck: ✅ CPU**

The CPU was identified as the primary bottleneck. It reached 100% utilization starting at 40 concurrent users and remained maxed through the 75 and 100-user levels. The system runs on a development machine (local XAMPP server) which is not hardware-optimized for production-level concurrent workloads.

**Secondary Factor: Server Configuration**

XAMPP's default Apache configuration uses conservative default values for `MaxRequestWorkers` and `ThreadsPerChild`, which limit the number of simultaneous connections Apache can handle efficiently. Tuning these settings for production deployment would improve throughput under high concurrency.

**Memory was NOT a bottleneck** — RAM utilization stayed within a manageable range of 81–87% across all test levels, with sufficient headroom remaining throughout.

**Summary:** For the system's intended deployment (approximately 50 expected users), the results confirm that the system performs well under Normal to Moderate load (5–20 users) with fast response times and zero errors. Performance degrades at 40+ concurrent users on the current hardware, but the system remains functional and available. For production deployment serving the full expected user base, migrating to a dedicated server with higher CPU capacity and optimized Apache configuration is recommended.
