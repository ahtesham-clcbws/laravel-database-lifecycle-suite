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
| **SQLite** | 3.54.0 | In-memory & File-based | 149 | 492 | **PASS (0 failures)** |
| **MySQL** | 8.4.2 (Isolated Disposable Service) | TCP PDO (Port 3308) | 149 | 488 (2 driver-specific skips) | **PASS (0 failures)** |
| **MariaDB** | 13.0.2-MariaDB (Isolated Disposable Service) | TCP PDO (Port 3307) | 149 | 488 (2 driver-specific skips) | **PASS (0 failures)** |
| **PostgreSQL** | 18.6 (Local Service) | TCP / Socket PDO (Port 5432) | 149 | 491 (1 driver-specific skip) | **PASS (0 failures)** |

*\*Documentation of Intentional Driver-Specific Skipped Tests*:
- **PostgreSQL (`1 skip`)**: `test_it_permits_backtick_quoted_identifiers` is skipped because backtick quoting (`` `column` ``) is MySQL/MariaDB syntax, whereas PostgreSQL adheres to ANSI standard double quotes (`"`).
- **MySQL / MariaDB (`2 skips`)**: 
  1. `test_it_detects_unindexed_foreign_keys` is skipped because MySQL and MariaDB InnoDB automatically and natively create backing index structures for foreign key columns upon constraint creation. Testing an unindexed foreign key is prevented by the database engine.
  2. `test_it_permits_double_quoted_identifiers` is skipped because MySQL/MariaDB treats double quotes as string literal delimiters unless `ANSI_QUOTES` mode is explicitly enabled. Standard SQL in MySQL uses backticks.

---

## 3. GitHub Actions CI Matrix Proof (Run #39741386110 & #39745618000)

All 12 jobs in the official GitHub Actions workflow passed against tested source revision `fecea8d` (Run #39741386110) and certified evidence revision `e13a2e4` (Run #39745618000):
1. `MySQL Integration`
2. `Code Quality (Pint & PHPStan)`
3. `PHP 8.3 - L11.*`
4. `PostgreSQL Integration`
5. `PHP 8.3 - L13.*`
6. `PHP 8.3 - L12.*`
7. `PHP 8.4 - L11.*`
8. `MariaDB Integration`
9. `PHP 8.4 - L12.*`
10. `PHP 8.4 - L13.*`
11. `PHP 8.2 - L11.*`
12. `PHP 8.2 - L12.*`
