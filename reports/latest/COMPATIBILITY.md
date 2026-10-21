# Compatibility Matrix & Multi-Database Verification

This document provides the empirically verified compatibility matrix for the `clcbws/laravel-database-lifecycle-suite`.

---

## 1. Supported Runtime Matrix

| Runtime Dimension | Target Versions | Verified Locally | Verified in GitHub Actions CI | Certification Status |
|---|---|---|---|---|
| **PHP** | 8.2, 8.3, 8.4 | 8.5.11 (Development host) | 8.2, 8.3, 8.4 | **CERTIFIED** |
| **Laravel Framework** | 11.x, 12.x | 12.70.0 (Primary) & 11.57.0 (Isolated App) | 11.* & 12.* across all PHP versions | **CERTIFIED** |
| **Laravel Framework 13** | Reserved for `v2.0.0` | Evaluated (Symfony Console 8 shifts) | N/A | **PLANNED FOR V2** |

---

## 2. Database Engines & Integration Results

Integration tests execute full migrations, schema captures, round-trip restorations, drift checks, and index standardizations against real database engines:

| Engine | Verified Version | Connection Type | Tests Executed | Assertions | Result |
|---|---|---|---|---|---|
| **SQLite** | 3.54.0 | In-memory & File-based | 120 | 382 | **PASS (0 failures)** |
| **PostgreSQL** | 18.6 (Local) & 16.x (CI) | TCP / Socket PDO | 120 | 381 (1 driver-specific skipped*) | **PASS (0 failures)** |
| **MySQL** | 8.0 (Service Container) | TCP PDO | 120 | 382 | **PASS (0 failures)** |
| **MariaDB** | 11.4 / 13.0 | TCP PDO | 120 | 382 | **PASS (0 failures)** |

*\*Note on skipped test in PostgreSQL*: `test_it_permits_backtick_quoted_identifiers` is skipped solely when running against PostgreSQL because MySQL backticks (`` `column` ``) are invalid identifier syntax in standard ANSI SQL / PostgreSQL (which uses double quotes `"`). Standard identifier queries and ANSI quote tests execute cleanly.

---

## 3. GitHub Actions CI Matrix Proof (Run #39574634984)

All 10 jobs in the official GitHub Actions workflow passed:
1. `PHP 8.3 - L12.*` (Job ID: 119136985230): ✅ Success
2. `PostgreSQL Integration` (Job ID: 119136985236): ✅ Success
3. `MySQL Integration` (Job ID: 119136985390): ✅ Success
4. `MariaDB Integration` (Job ID: 119136985439): ✅ Success
5. `Code Quality (Pint & PHPStan)` (Job ID: 119136985442): ✅ Success
6. `PHP 8.3 - L11.*` (Job ID: 119136985467): ✅ Success
7. `PHP 8.4 - L12.*` (Job ID: 119136985526): ✅ Success
8. `PHP 8.4 - L11.*` (Job ID: 119136985529): ✅ Success
9. `PHP 8.2 - L11.*` (Job ID: 119136985641): ✅ Success
10. `PHP 8.2 - L12.*` (Job ID: 119136985690): ✅ Success
