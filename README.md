# Laravel Database Lifecycle Suite

[![Latest Version on GitHub](https://img.shields.io/github/v/release/ahtesham-clcbws/laravel-database-lifecycle-suite?include_prereleases&style=flat-square)](https://github.com/ahtesham-clcbws/laravel-database-lifecycle-suite/releases)
[![Total Downloads](https://img.shields.io/packagist/dt/clcbws/laravel-database-lifecycle-suite.svg?style=flat-square)](https://packagist.org/packages/clcbws/laravel-database-lifecycle-suite)
[![License](https://img.shields.io/github/license/ahtesham-clcbws/laravel-database-lifecycle-suite?style=flat-square)](LICENSE.md)

**Laravel Database Lifecycle Suite** is a database management, auditing, and reverse-engineering toolkit for Laravel applications. It provides visibility into schema consistency, index efficiency, reference data drift, and database health metrics.

---

## 📋 Compatibility Matrix

- **PHP**: `^8.2` (Verified across PHP 8.2, 8.3, 8.4, and 8.5)
- **Framework**: Laravel `11.x` and `12.x`
- **Supported Databases**:
  - SQLite (3.35+)
  - MySQL (8.0+)
  - MariaDB (10.6+)
  - PostgreSQL (14+)

> [!NOTE]
> **Laravel 13 Support Statement**: Laravel 13 dropped PHP 8.2 support (requiring PHP 8.3+) and introduced substantial breaking shifts in console command contracts and DBAL schema metadata representations. In accordance with Semantic Versioning (SemVer), official Laravel 13 support will be released in the upcoming 2.0 major version to preserve PHP 8.2 compatibility for 1.x installations.

---

## 🛡️ Production Safety Invariants

1. **Schema Snapshots are NOT Data Backups**:
   - Schema snapshots capture structural definitions (tables, columns, primary keys, indexes, foreign keys).
   - Snapshots contain **no table rows, data records, or secrets**.
   - Restoring a snapshot will not recover lost data.
2. **Safe Seeder Scaffolds**:
   - `LegacyBridge` generates empty, safe seeders with placeholder documentation.
   - Live application data, user credentials, tokens, and PII are never exported to generated PHP files.
3. **Destructive Restore Protection**:
   - Snapshot restoration is non-destructive by default and rejects dropping tables without explicit `--recreate`.
   - In production environments, destructive table recreation requires confirmation and `--force`.
4. **Preflight Validation & Path Traversal Prevention**:
   - Snapshot restoration conducts complete preflight validation across all tables before making any database modifications.
   - Snapshot filenames are sanitized against directory traversal attacks.
5. **Heuristic PII Auditing**:
   - PII detection uses regex naming conventions (`email`, `phone`, `ssn`, `card`). It is an advisory tool and does not inspect table contents.
6. **Zero-Trust Dependency Security**:
   - Broad package-level advisory exceptions are prohibited. All production dependencies are continuously scanned with `composer audit` and `composer validate --strict`.
   - Historical upstream EOL advisories for Laravel 11 matrix compatibility are explicitly pinned by advisory ID (`policy.advisories.ignore-id` and `audit.ignore`), preventing unvetted security drift while preserving full auditing across all active components.

---

## 📦 Installation

Install via Composer:

```bash
composer require clcbws/laravel-database-lifecycle-suite
```

Publish configuration:

```bash
php artisan vendor:publish --provider="Clcbws\DatabaseLifecycleSuite\DatabaseLifecycleSuiteServiceProvider" --tag="config"
```

*(Optional)* For structural migration drift detection in the Lifecycle Scorecard, install Laravel Schema Sentinel:

```bash
composer require clcbws/laravel-schema-sentinel
```

---

## ⚙️ Configuration

The published config file `config/database-lifecycle-suite.php` includes:

- `index_naming_pattern`: Template for index names (default: `{table}_{column}_{type}`).
- `source_connection`: Default connection acting as the source of truth for data drift.
- `data_audit_tables`: Array of lookup/reference tables monitored for row-level drift.
- `model_path`: Location of Eloquent models for cast and soft-delete audits (default: `app/Models`).
- `pii_patterns`: Configurable regex patterns for heuristic PII discovery.

---

## 🚀 Commands & Usage

### 1. Database Lifecycle Scorecard
Runs an audit across all lifecycle dimensions and returns an overall health score:

```bash
# Terminal table output
php artisan db:lifecycle-status

# CI-friendly strict exit code (fails if any check fails)
php artisan db:lifecycle-status --strict

# Machine-readable JSON output
php artisan db:lifecycle-status --format=json
```

Status badges reported per check:
- `PASS`: Healthy and compliant.
- `WARNING`: Advisory non-critical drift detected.
- `FAIL`: Structural defect or unindexed foreign key detected.
- `SKIPPED`: Optional dependency not installed (e.g., Schema Sentinel).
- `NOT CONFIGURED`: Aspect not yet configured (e.g., `data_audit_tables`).
- `ERROR`: An exception occurred during execution.

### 2. Schema Snapshots & Safe Restoration
Capture, compare, and restore structural schemas:

```bash
# Capture schema state
php artisan db:snapshot baseline

# Compare snapshots or compare snapshot against live database
php artisan db:snapshot-diff baseline --live

# Dry-run restoration preview (modifies nothing)
php artisan db:snapshot-restore baseline --dry-run

# Safe restoration (creates missing tables without dropping existing ones)
php artisan db:snapshot-restore baseline

# Destructive recreation (drops and recreates tables with safeguards)
php artisan db:snapshot-restore baseline --recreate
```

### 3. Data Drift Deep Dive
Compares row data in lookup/reference tables between source and destination connections:

```bash
# Audit reference tables for drift
php artisan db:data-drift --source=production --table=roles

# Fail in CI if drift exists
php artisan db:data-drift --source=production --table=roles --strict

# Interactive synchronization with diff previews
php artisan db:data-drift --source=production --table=roles --fix
```

### 4. Legacy Bridge (Reverse-Engineering)
Generates Eloquent models, migrations, factories, and safe seeder scaffolds from existing database tables:

```bash
# Reverse-engineer all tables on default connection
php artisan db:legacy-bridge

# Reverse-engineer specific connection and tables
php artisan db:legacy-bridge --database=legacy_db --tables=users,orders,order_items
```

Features:
- Preserves primary keys, composite keys, and custom column types.
- Disambiguates relationship method names when multiple foreign keys target the same table.
- Generates safe seeder scaffolds with zero live data exposure.
- Preserves `onDelete` and `onUpdate` foreign key actions.

### 5. Index Standardization & Optimization
Audit and align indexes with naming standards and database performance:

```bash
# Audit index names against naming convention
php artisan db:standardize-indexes --dry-run

# Rename non-standard indexes (with collision prevention)
php artisan db:standardize-indexes

# Detect unindexed foreign keys and potential foreign key columns
php artisan db:index-audit --strict

# Detect redundant / overlapping composite indexes
php artisan db:index-redundancy --strict
```

### 6. Read-Only Query Analyzer
Inspect SQL execution plans using driver-appropriate EXPLAIN syntax:

```bash
php artisan db:explain "SELECT * FROM users WHERE email = 'test@example.com'"
```

Supported Drivers:
- **SQLite**: Parses `EXPLAIN QUERY PLAN` for table scans and index usage.
- **MySQL / MariaDB**: Evaluates `EXPLAIN` for full scans, temporary tables, and filesorts.
- **PostgreSQL**: Evaluates `EXPLAIN` for sequential scans and sort algorithms.

*Safety note: Only read-only `SELECT` and `WITH` statements are permitted. Multi-statement queries are strictly rejected.*

### 7. Additional Schema & Model Auditors

```bash
# Audit Eloquent $casts against database column types
php artisan db:cast-audit --strict

# Audit Eloquent SoftDeletes trait vs deleted_at columns
php artisan db:soft-delete-audit --strict

# Audit cross-table column type uniformity for shared column names
php artisan db:column-type-audit --strict

# Audit table naming conventions (snake_case plural)
php artisan db:convention-audit --strict

# Report physical disk sizes and row counts
php artisan db:size-report

# Ping configured database connections and measure latency
php artisan db:connection-check

# Generate Mermaid.js Entity Relationship Diagram (ERD)
php artisan db:erd --output=docs/schema.mmd
```

---

## 🔌 Compatibility & Roadmap

| Platform / Component | Supported Versions | Notes |
| :--- | :--- | :--- |
| **PHP** | `^8.2 \| ^8.3 \| ^8.4` | Verified in multi-version test matrix |
| **Laravel Framework** | `^11.0 \| ^12.0` | Verified on Laravel 11.x and 12.x |
| **Database Engines** | SQLite 3, MySQL 8.0+, MariaDB 11.4+, PostgreSQL 16+ | Integration tested via automated CI containers |
| **Laravel 13** | Planned for `v2.0.0` | Laravel 13 dropped PHP 8.2 support (requiring PHP ^8.3), decoupled Symfony Console 8 command exit constants, and tightened schema metadata docblocks. To preserve strict SemVer stability and PHP 8.2 support for 1.x installations, full Laravel 13 support will be released in major version 2.0.0. |

### 🛡️ Safety Boundaries & Unsupported Features

- **Dependency Security**:
  - Zero vulnerability advisory bypasses or ignored security policies in `composer.json`. Native `composer audit` is enforced strictly in CI without exceptions.

- **Schema Restoration Safe Boundaries**:
  - Automatically identifies and rejects lossy or unportable features before mutating databases (virtual/generated columns, unsupported vendor geometry/blob types, and incompatible foreign key actions like `SET DEFAULT` on MySQL).
  - Explicit integer primary keys and column signedness (including `unsigned` integers) are preserved without distortion.
  - Destructive recreation (`--recreate`) is strictly blocked in production environments.
  - *MySQL/MariaDB Implicit DDL Commits*: MySQL auto-commits active transactions upon executing DDL statements (`CREATE TABLE`, `DROP TABLE`). To prevent partial restorations, the suite executes complete preflight validation across the snapshot prior to issuing any DDL commands.

- **Query Analyzer Safe Subset**:
  - The Query Analyzer strictly enforces a safe, read-only query subset.
  - Queries must be standard `SELECT` or non-recursive `WITH ... AS (SELECT ...) SELECT ...`.
  - The following constructs are strictly rejected:
    - Modifying DDL / DML (`INSERT`, `UPDATE`, `DELETE`, `DROP`, `ALTER`, `TRUNCATE`, `CREATE`, `REPLACE`, `MERGE`, etc.)
    - Administrative & engine control commands (`PRAGMA`, `ATTACH`, `DETACH`, `SET`, `SHOW`, `KILL`, `SHUTDOWN`, etc.)
    - Transaction control (`COMMIT`, `ROLLBACK`, `BEGIN`, `START`, `SAVEPOINT`)
    - Locking clauses (`FOR UPDATE`, `FOR SHARE`, `LOCK IN SHARE MODE`, `GET_LOCK()`)
    - Dollar-quoted strings (`$$...$$` in PostgreSQL)
    - Side-effecting execution functions (`SLEEP()`, `PG_SLEEP()`, `BENCHMARK()`)
    - Chained / multi-statement SQL (semicolons outside string literals)
    - Unclosed comments (`/*`) or unmatched quotations

- **Test Database Isolation & Ownership**:
  - Test suites enforce fail-closed disposable database protection: cleanup runs only when `allow_destructive_test_cleanup` is enabled, environment is `'testing'`, host is on an approved local/container whitelist, and database identifiers pass strict alphanumeric validation (`^[a-zA-Z0-9_]{1,63}$`) preventing SQL injection.
  - *Ownership flags alone are never proof*: Well-known production or system database names (`production`, `app`, `laravel`, `mysql`, `postgres`, etc.) are unconditionally rejected even if ownership flags are present.
  - Mandatory runtime socket database identities are verified via live engine queries (`SELECT DATABASE()` / `SELECT current_database()`) and fail closed on unverified identities or socket errors.
  - Source and destination connections must be independently provisioned and verified as isolated to prevent unintended shared database drops.

---

## 🧪 Testing

Execute test suite:

```bash
composer test
```

Run code formatting check:

```bash
composer lint
```

Run static analysis (PHPStan):

```bash
composer analyze
```

Execute full offline local CI verification suite (Pint, PHPStan, Composer Audit, SQLite, and PostgreSQL):

```bash
composer test:ci
```

---

## 📄 License

The MIT License (MIT). See [LICENSE.md](LICENSE.md) for details.
