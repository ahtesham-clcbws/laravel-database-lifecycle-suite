# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]
### Added
- **Production Safety Safeguards**:
  - Preflight schema validation in `SchemaSnapshotManager` before applying any restore operations.
  - Snapshot format versioning (`version: "1.0"`) and JSON integrity checks.
  - Path traversal protections preventing arbitrary file access via snapshot names.
  - `--dry-run` preview flag on `db:snapshot-restore`.
  - Destructive recreation guard (`--recreate`) requiring explicit confirmation and `--force` in production.
  - Clear CLI and documentation warnings clarifying that schema snapshots restore structure only, not data backups.
- **Seeder Security**:
  - Enforced safe seeder scaffolds in `LegacyBridge` with complete exclusion of live database records, credentials, and sensitive data.
- **Data Drift Hardening**:
  - Support for composite primary keys and graceful error reporting for tables lacking primary keys.
  - Memory-bounded comparison (`--limit`, default 1,000 records) to prevent OOM on large datasets.
  - Multi-connection validation verifying existence of tables on both source and destination connections.
  - Transaction-wrapped row synchronization with explicit change previews.
- **Index Engine Enhancements**:
  - Configurable `index_naming_pattern` interpolation in `IndexStandardizer`.
  - 63-character cross-driver identifier truncation with deterministic hashing.
  - Collision detection preventing index renaming into existing index names.
  - Protected single-column `unique` constraints in `RedundantIndexDetector` from false-positive redundancy by composite unique indexes.
- **Query Analyzer Upgrades**:
  - Read-only statement validation strictly permitting `SELECT` and `WITH` queries.
  - Rejection of multi-statement SQL chains (`;`) to prevent mutation injections.
  - Support for SQLite `EXPLAIN QUERY PLAN` plan interpretation and MariaDB driver aliases.
- **Scorecard Modernization**:
  - Deterministic health scoring algorithm (0-100%) excluding unconfigured or skipped checks from the denominator.
  - Standardized check states: `PASS`, `WARNING`, `FAIL`, `SKIPPED`, `NOT CONFIGURED`, `ERROR`.
  - Machine-readable JSON output via `--format=json`.
  - Strict CI mode via `--strict` flag across audit commands.
- **GitHub Actions CI Workflow**:
  - Multi-version testing across PHP 8.2, 8.3, 8.4 and Laravel 11.x, 12.x.
  - Automated Pint formatting checks and PHPStan level 5 analysis.

### Changed
- Refactored `LegacyBridge` model generation to respect custom connections, primary keys, and auto-increment properties.
- Disambiguated relationship method names in `LegacyBridge` when multiple foreign keys target the same model.
- Fixed table discovery in `LegacyBridgeCommand` across SQLite, PostgreSQL, and MySQL.
- Removed hardcoded version tag from `composer.json` for Git-tag-based packaging.

## [1.0.0] - 2026-05-06
### Added
- Initial release of Laravel Database Lifecycle Suite.
- Index Naming Standardizer, Data Drift Deep Dive, Legacy Bridge reverse-engineering.
- Missing Index Health Check, Schema Snapshots, and Mermaid.js ERD generation.
- Lifecycle Scorecard overview.
