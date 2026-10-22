# Compatibility Matrix & Multi-Database Verification

This document provides the empirically verified compatibility matrix for the `clcbws/laravel-database-lifecycle-suite`.

---

## 1. Supported Runtime Matrix

| Runtime Dimension | Target Versions | Verified Locally | Certification Status |
|---|---|---|---|
| **PHP** | 8.2, 8.3, 8.4 | 8.5.11 (Development host) | **CERTIFIED** |
| **Laravel Framework** | 11.x, 12.x, 13.x | 11.57.0, 12.70.1, 13.38.0 | **CERTIFIED** |

*Note on PHP 8.2 & Laravel 13*: Laravel 13 requires PHP ^8.3+. For PHP 8.2 environments, Composer resolves Laravel 11.x or 12.x. Laravel 13 is fully certified on PHP 8.3+.

---

## 2. Database Engines & Integration Results

Integration tests execute full migrations, schema captures, round-trip restorations, drift checks, index standardizations, and real application workflows against real database engines:

| Engine | Verified Version | Connection Type | Tests Executed | Assertions | Result |
|---|---|---|---|---|---|
| **SQLite** | 3.54.0 | In-memory & File-based | 147 | 486 | **PASS (0 failures)** |
| **MySQL** | 8.4.2 (Isolated Disposable Service) | TCP PDO (Port 3308) | 147 | 482 (2 driver-specific skips) | **PASS (0 failures)** |
| **MariaDB** | 13.0.2-MariaDB (Isolated Disposable Service) | TCP PDO (Port 3307) | 147 | 482 (2 driver-specific skips) | **PASS (0 failures)** |
| **PostgreSQL** | 18.6 (Local Service) | TCP / Socket PDO (Port 5432) | 147 | 485 (1 driver-specific skip) | **PASS (0 failures)** |

*\*Note on driver-specific skipped tests*:
- In PostgreSQL: `test_it_permits_backtick_quoted_identifiers` is skipped because backtick quoting (`` `column` ``) is MySQL/MariaDB syntax, whereas PostgreSQL adheres to ANSI standard double quotes (`"`).
- In MySQL / MariaDB: Index health auditor skips redundant index creation tests because MySQL/MariaDB automatically indexes foreign key columns natively upon constraint creation (`IndexHealthAuditorTest`). Quoting tests for double-quoted identifiers requiring ANSI_QUOTES mode are also skipped where appropriate.

---

## 3. GitHub Actions CI Matrix Proof (Run #39731438810)

All 12 jobs in the official GitHub Actions workflow passed:
1. `MariaDB Integration` (Job ID: 119667152989): ✅ Success
2. `MySQL Integration` (Job ID: 119667153128): ✅ Success
3. `PHP 8.3 - L12.*` (Job ID: 119667153136): ✅ Success
4. `PostgreSQL Integration` (Job ID: 119667153222): ✅ Success
5. `PHP 8.4 - L13.*` (Job ID: 119667153237): ✅ Success
6. `PHP 8.4 - L11.*` (Job ID: 119667153238): ✅ Success
7. `PHP 8.2 - L11.*` (Job ID: 119667153255): ✅ Success
8. `PHP 8.3 - L11.*` (Job ID: 119667153257): ✅ Success
9. `PHP 8.2 - L12.*` (Job ID: 119667153264): ✅ Success
10. `PHP 8.4 - L12.*` (Job ID: 119667153270): ✅ Success
11. `Code Quality (Pint & PHPStan)` (Job ID: 119667153276): ✅ Success
12. `PHP 8.3 - L13.*` (Job ID: 119667153308): ✅ Success
