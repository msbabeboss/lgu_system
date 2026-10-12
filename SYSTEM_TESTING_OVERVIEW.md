# System Testing Overview
## Development of a Centralized Local Government Unit Information Management System

---

## What is System Testing?

System testing is the process of evaluating a fully integrated software system to verify that it meets its specified requirements and performs correctly under various conditions. It is conducted after all individual modules have been developed and unit-tested, and before the system is presented to the client or deployed for actual use.

For this capstone project — the **Centralized LGU Information Management System** — system testing was performed to ensure that the system can handle real-world usage conditions, responds correctly to user interactions, and remains stable and available under different levels of workload.

---

## Purpose of System Testing

The system was tested with the following goals:

- **Verify functional correctness** — ensure that all modules (resident management, household management, certificate issuance, blotter reports, etc.) work as intended
- **Validate performance** — measure how fast the system responds to user requests under normal and heavy workloads
- **Assess reliability** — determine whether the system produces accurate and consistent results without errors or crashes
- **Evaluate usability** — confirm that the system is accessible and easy to operate for its intended users (Super Admin and Barangay Administrators)
- **Confirm deployment readiness** — determine whether the system is ready to be presented to the client or deployed in an actual LGU environment

---

## Scope of Testing

The system testing covered the following areas:

### 1. Functional Testing
Verified that each module of the system performs its intended function:
- User login and authentication (with CSRF protection and optional 2FA)
- Resident and household record management (add, edit, view, archive, transfer)
- Certificate issuance and printing
- Blotter/incident report management
- Barangay officials management
- Reports generation and analytics (including ARIMA forecasting)
- Notification system
- Activity and audit logging
- Role-based access control (Super Admin vs. Barangay Admin)

### 2. Performance / Stress Testing
Measured how the system behaves under increasing concurrent user loads using **Apache JMeter 5.6.3**. Five load levels were tested:

| Load Level | Users | Result |
|-----------|-------|--------|
| Normal | 5 | Fast response (~39ms avg), 0 errors |
| Moderate | 20 | Stable (~42ms avg), 0 errors |
| High | 40 | Slowdown (~278ms avg, peak 2,819ms), 0 errors |
| Very High | 75 | Recovered (~49ms avg), 0 errors |
| Stress | 100 | Moderate delay (~169ms avg), 0 errors |

### 3. Security Testing
Verified that the system implements proper security measures:
- CSRF token validation on all forms
- Password hashing using bcrypt
- SQL injection prevention via PDO prepared statements
- Session management with secure cookie flags
- Role-based access isolation (barangay admins can only access their own barangay's data)
- Two-Factor Authentication (TOTP) available for Super Admin

### 4. Compatibility Testing
The system was tested across different access environments:
- Local access via `http://localhost/lgu_system`
- LAN access via custom hostname `http://CentralizedLGU/lgu_system`
- Remote access via Tailscale VPN at `https://lgu-server.tail434c74.ts.net`

---

## Testing Environment

| Component | Details |
|-----------|---------|
| Server | Local machine running XAMPP on Windows |
| Web Server | Apache 2.4.58 |
| PHP Version | PHP 8.0.30 |
| Database | MySQL / MariaDB 10.4.32 |
| Network | Tailscale Funnel (HTTPS via `lgu-server.tail434c74.ts.net`) |
| Stress Testing Tool | Apache JMeter 5.6.3 |
| System Version | 1.0.0 |

---

## Testing Approach

Testing was conducted in the following sequence:

1. **Unit-level verification** — individual modules were tested during development to confirm basic functionality
2. **Integration testing** — modules were tested together to verify that data flows correctly between components (e.g., adding a resident and verifying it appears in the dashboard count)
3. **Stress testing** — the fully integrated system was subjected to increasing concurrent user loads using JMeter to identify performance limits and bottlenecks
4. **Recovery testing** — after each stress level, the system was observed to verify it returned to normal operation without manual intervention

---

## Key Findings

- The system handled all five load levels with **0 errors and 100% availability**
- Performance degraded noticeably at **40 concurrent users** (High load), with maximum response time reaching 2,819ms — identified as the current hardware stress threshold
- The primary bottleneck was **CPU utilization**, which reached 100% at 40+ concurrent users on the development machine
- **Memory remained stable** throughout all tests (81–87%), indicating RAM is not a limiting factor
- The system is well-suited for its **expected user base of ~50 users**, as it performed efficiently at Normal and Moderate load levels which cover the typical daily usage scenario

---

## Conclusion

The Centralized LGU Information Management System successfully passed system testing under all defined conditions. It demonstrated functional correctness across all major modules, maintained zero error rates throughout all stress test levels, and recovered cleanly after each test. While CPU performance limits were reached at high concurrency on the current development hardware, the system is considered ready for deployment within its intended operational scope of approximately 50 concurrent users. For future scaling beyond this capacity, migration to a dedicated production server with optimized Apache configuration is recommended.
