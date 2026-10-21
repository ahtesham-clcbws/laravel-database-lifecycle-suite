# Known Technical Limitations & Architecture Scope

This document provides transparent disclosure regarding technical boundaries, unsupported database features, and intentional architectural non-goals of the `clcbws/laravel-database-lifecycle-suite`.

---

## 1. Schema Snapshots Are Structural Only (Not Data Backups)
- **Scope**: Schema snapshots capture table definitions, column types, nullability, defaults, primary keys, indexes, and foreign keys.
- **Limitation**: Snapshots **never** capture, serialize, or restore table rows, application state, or user data.
- **Guidance**: Use dedicated database backup utilities (e.g., `mysqldump`, `pg_dump`, or cloud volume snapshots) for data persistence.

---

## 2. Non-Transactional DDL on MySQL and MariaDB
- **Scope**: Restoration preflight validation checks identifier safety, foreign key target resolution, and unsupported types before issuing DDL statements.
- **Limitation**: On MySQL and MariaDB, DDL statements (`CREATE TABLE`, `ALTER TABLE`, `DROP TABLE`) cause implicit commits. If a mid-operation syntax or foreign key error occurs after creating 5 out of 10 tables, previously created tables cannot be rolled back via SQL transaction rollback.
- **Mitigation**: SchemaSnapshotManager executes preflight topological dependency sorting and column type validation to detect missing references prior to issuing the first DDL statement. When atomic DDL is required, use PostgreSQL or SQLite.

---

## 3. Query Analyzer Supported SQL Subset
- **Scope**: Strict regex and token analysis allows safe execution of single read-only `SELECT` statements and non-recursive `WITH ... (SELECT ...) SELECT ...` queries.
- **Limitation**: The analyzer does not implement a full Turing-complete SQL parser. It intentionally rejects:
  - CTE queries containing mutation clauses (`INSERT`, `UPDATE`, `DELETE`)
  - Queries with `SELECT ... INTO` or outfile clauses
  - Locking clauses (`FOR UPDATE`, `FOR SHARE`, `LOCK IN SHARE MODE`)
  - Side-effecting and sleep functions (`SLEEP()`, `GET_LOCK()`, `BENCHMARK()`)
  - PostgreSQL dollar-quoted strings (`$$...$$`)
  - Multi-statement sequences (semicolon-separated)
  - Unclosed multi-line block comments (`/* ...`)
- **Guidance**: Any query whose read-only status cannot be proven with absolute certainty is rejected fail-closed with `InvalidArgumentException`.

---

## 4. Heuristic PII Auditing
- **Scope**: `db:pii-scan` identifies columns matching standard naming conventions for sensitive information (`email`, `phone`, `ssn`, `card`, `password`, `token`).
- **Limitation**: PII auditing is an advisory tool based solely on schema column nomenclature. It does **not** inspect row contents, analyze unstructured text, or certify legal compliance with GDPR, HIPAA, or CCPA.

---

## 5. Reverse-Engineering (Legacy Bridge) Fidelity
- **Scope**: Reverse-engineers tables into clean Eloquent models, migrations, factories, and safe seeder scaffolds.
- **Limitation**: Highly vendor-specific constructs (such as PostgreSQL user-defined domain types, complex partition keys, custom spatial indexes, and composite expression indexes) cannot be mapped losslessly to Laravel Fluent blueprint methods and are cleanly skipped or mapped to their closest base type.
- **Seeder Data Safety**: Generated seeders produce empty or synthetic template rows; live database records and credentials are never extracted to seed files.

---

## 6. Laravel 13 Compatibility Policy
- **Scope**: `clcbws/laravel-database-lifecycle-suite` `v1.x` officially supports **Laravel 11.x and 12.x** on **PHP 8.2, 8.3, and 8.4**.
- **Limitation**: Laravel 13 drops PHP 8.2 support and migrates to Symfony Console 8 (which decouples exit code constants). While the core migration and database abstractions remain compatible, official Laravel 13 support will be released under `v2.0.0` following SemVer principles.
