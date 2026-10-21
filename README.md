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

---

## 📄 License

The MIT License (MIT). See [LICENSE.md](LICENSE.md) for details.
