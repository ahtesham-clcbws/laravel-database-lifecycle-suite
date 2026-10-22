# Laravel Database Lifecycle Suite

[![Latest Version on Packagist](https://img.shields.io/packagist/v/clcbws/laravel-database-lifecycle-suite.svg?style=flat-square)](https://packagist.org/packages/clcbws/laravel-database-lifecycle-suite)
[![GitHub Tests Action Status](https://img.shields.io/github/actions/workflow/status/ahtesham-clcbws/laravel-database-lifecycle-suite/ci.yml?branch=master&label=tests&style=flat-square)](https://github.com/ahtesham-clcbws/laravel-database-lifecycle-suite/actions)
[![Total Downloads](https://img.shields.io/packagist/dt/clcbws/laravel-database-lifecycle-suite.svg?style=flat-square)](https://packagist.org/packages/clcbws/laravel-database-lifecycle-suite)
[![Monthly Downloads](https://img.shields.io/packagist/dm/clcbws/laravel-database-lifecycle-suite.svg?style=flat-square)](https://packagist.org/packages/clcbws/laravel-database-lifecycle-suite)
[![GitHub Stars](https://img.shields.io/github/stars/ahtesham-clcbws/laravel-database-lifecycle-suite?style=flat-square)](https://github.com/ahtesham-clcbws/laravel-database-lifecycle-suite/stargazers)
[![License](https://img.shields.io/github/license/ahtesham-clcbws/laravel-database-lifecycle-suite?style=flat-square)](LICENSE.md)
[![PHPStan Level](https://img.shields.io/badge/PHPStan-level%205-brightgreen?style=flat-square)](phpstan.neon)
[![Code Style](https://img.shields.io/badge/code%20style-pint-blue?style=flat-square)](https://laravel.com/docs/pint)

**Laravel Database Lifecycle Suite** is an enterprise-grade database management, architectural auditing, and safe reverse-engineering toolkit for Laravel applications. It provides deep visibility into database health metrics, schema consistency, redundant index detection, query plan safety, and reference data synchronization across SQLite, MySQL, MariaDB, and PostgreSQL.

---

## 📋 Compatibility Matrix

- **PHP**: `^8.2`, `^8.3`, `^8.4` (Tested and verified across PHP 8.2 through 8.5)
- **Framework**: Laravel `11.x`, `12.x`, and `13.x`
- **Supported Database Engines**:
  - SQLite (3.35+)
  - MySQL (8.0+)
  - MariaDB (10.6+)
  - PostgreSQL (14+)

> [!NOTE]
> **Laravel 11, 12 & 13 Support Statement**: Laravel 11.x, 12.x, and 13.x are fully supported and verified via real-world application integration tests across Scenarios A through L (schema snapshots, drift detection, seeder safety, and model generation). Note that Laravel 13 requires PHP ^8.3+. On PHP 8.2 environments, Composer resolves Laravel 11.x or 12.x.

---

## 🛡️ Production Safety Invariants

1. **Schema Snapshots are NOT Data Backups**:
   - Schema snapshots capture structural definitions (tables, columns, primary keys, indexes, foreign keys).
   - Snapshots contain **no table rows, data records, credentials, or secrets**.
   - Restoring a snapshot will not recover lost data.
2. **Safe Seeder Scaffolds (Zero Live Data Leaks)**:
   - `LegacyBridge` generates empty, safe seeders with placeholder documentation.
   - Live application data, user credentials, tokens, and PII are never exported to generated PHP files.
3. **Destructive Restore Protection**:
   - Snapshot restoration is non-destructive by default and skips existing tables without dropping them.
   - Dropping and recreating existing tables (`--recreate`) is strictly blocked in production environments (`app()->environment('production')`). Even `--force` cannot bypass this block in production. In non-production environments, `--recreate` requires explicit user confirmation or `--force`.
4. **Preflight Validation & Path Traversal Prevention**:
   - Snapshot restoration conducts complete preflight validation across all tables before making any database modifications.
   - Snapshot filenames are sanitized against directory traversal attacks via strict regex matching (`^[a-zA-Z0-9_\-\.]+$`).
5. **Pessimistic Locking & Competing Insert Rejection**:
   - Data drift synchronization executes within transactions using `lockForUpdate()`. Competing inserts during review are detected and rejected to prevent silent row overwrites.
6. **Strict Read-Only Query Analyzer**:
   - The query plan analyzer strips SQL comments, quotes literals, and rejects mutating CTEs (`INSERT`, `UPDATE`, `DELETE`, etc.), locking clauses (`FOR UPDATE`), administrative commands, and dollar quoting.
7. **Zero-Trust Dependency Security**:
   - Active dependencies in `composer.lock` are continuously verified with native `composer audit` and `composer validate --strict` reporting 0 vulnerabilities.

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

## 🚀 Commands & Features

### 1. Database Lifecycle Scorecard (`db:lifecycle-status`)
Runs a comprehensive audit across all lifecycle dimensions and returns an overall health score:

```bash
# Terminal table output
php artisan db:lifecycle-status

# CI-friendly strict exit code (returns exit code 1 if any check fails)
php artisan db:lifecycle-status --strict

# Machine-readable JSON output
php artisan db:lifecycle-status --format=json
```

Status badges reported per check:
- `PASS`: Healthy and compliant.
- `WARNING`: Advisory recommendation (e.g. minor convention discrepancy).
- `FAIL`: Structural defect or optimization gap detected.
- `SKIPPED`: Optional integration (e.g. Schema Sentinel not present).

---

### 2. Schema Snapshot & Restoration (`db:snapshot`, `db:snapshot-diff`, `db:snapshot-restore`)
Capture structural schemas into deterministic JSON files, inspect schema drift between snapshots or against live databases, and safely restore:

```bash
# Capture live schema
php artisan db:snapshot initial_schema

# Diff live database against a captured snapshot
php artisan db:snapshot-diff initial_schema --live

# Compare two snapshots on disk
php artisan db:snapshot-diff baseline_v1 v2_candidate

# Dry-run restoration (preview changes without modifying database)
php artisan db:snapshot-restore initial_schema --dry-run

# Non-destructive restoration (skips existing tables)
php artisan db:snapshot-restore initial_schema

# Destructive recreation (DROPS and recreates existing tables; BLOCKED in production)
php artisan db:snapshot-restore initial_schema --recreate
```

---

### 3. Legacy Bridge Reverse Engineering (`db:legacy-bridge`)
Reverse-engineer an existing database into modular Laravel artifacts (migrations, Eloquent models with relations, 100% synthetic seeders, and factories):

```bash
# Reverse-engineer all tables
php artisan db:legacy-bridge

# Reverse-engineer specific tables
php artisan db:legacy-bridge --tables=users,orders,products

# Overwrite existing generated artifacts
php artisan db:legacy-bridge --force
```

---

### 4. Reference Data Drift Detector (`db:data-drift`)
Detect and synchronize reference/lookup table data drift across connections:

```bash
# Detect data drift between live database and source
php artisan db:data-drift --source=central_db --table=roles

# Strict mode for CI
php artisan db:data-drift --source=central_db --table=roles --strict

# Interactively review and apply drift fixes with pessimistic locking
php artisan db:data-drift --source=central_db --table=roles --fix
```

---

### 5. Index Auditing & Standardization (`db:index-audit`, `db:index-redundancy`, `db:standardize-indexes`)
Detect missing foreign key indexes, locate overlapping or redundant indexes, and standardize index names across all tables:

```bash
# Detect foreign keys missing an index
php artisan db:index-audit --strict

# Scan for overlapping/redundant secondary indexes
php artisan db:index-redundancy --strict

# Preview index name standardization changes
php artisan db:standardize-indexes --dry-run

# Apply index name standardization
php artisan db:standardize-indexes
```

---

### 6. Read-Only Query Plan Analyzer (`db:explain`)
Analyze SQL execution plans safely across database engines:

```bash
php artisan db:explain "SELECT * FROM users WHERE email = 'admin@example.com'"
```

- **SQLite**: Formats query plan with index usage details.
- **MySQL / MariaDB**: Evaluates `EXPLAIN` for full table scans, filesorts, and temporary tables.
- **PostgreSQL**: Evaluates `EXPLAIN` for sequential scans and execution costs.

*Safety invariant: Strictly enforces read-only `SELECT` and non-recursive `WITH ... SELECT` queries. Mutating CTEs and locking clauses are rejected fail-closed.*

---

### 7. Structural & Integrity Audits

```bash
# Audit Eloquent $casts against database column types
php artisan db:cast-audit --strict

# Audit Eloquent SoftDeletes trait vs deleted_at columns
php artisan db:soft-delete-audit --strict

# Audit cross-table column type uniformity for shared column names
php artisan db:column-type-audit --strict

# Audit table naming conventions (snake_case plural)
php artisan db:convention-audit --strict

# Scan table columns heuristically for PII (names, emails, phones, SSNs)
php artisan db:pii-scan --strict

# Report physical disk sizes and row counts
php artisan db:size-report

# Ping configured database connections and measure latency
php artisan db:connection-check

# Generate Mermaid.js Entity Relationship Diagram (ERD)
php artisan db:erd --output=docs/schema.mmd
```

---

## 🧪 Testing & Verification

Execute full test suite:

```bash
composer test
```

Run code formatting check (Laravel Pint):

```bash
composer lint
```

Run static analysis (PHPStan Level 5):

```bash
composer analyze
```

Execute comprehensive local release verification (Multi-engine database matrix, Pint, PHPStan, Composer Audit, and Laravel integration harness):

```bash
bash scripts/verify-release.sh --database=all --laravel=all
```

---

## 📄 License

The MIT License (MIT). See [LICENSE.md](LICENSE.md) for details.
