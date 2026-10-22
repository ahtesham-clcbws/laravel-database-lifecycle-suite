# Local Test Execution Results

**Execution Run ID**: `run_20261022_090800_295758`  
**Run Start**: 2026-10-22 09:08:00 UTC  
**Run End**: 2026-10-22 09:12:30 UTC  
**Overall Status**: **PASS**  
**Tested Source Revision (`SOURCE_SHA`)**: `fecea8d4ff2f849b088c9cb6244bd5d1417039b0`  
**Certified Evidence Revision (`CERTIFIED_EVIDENCE_SHA`)**: `75a8d011d44dcc24d97a46ac312cda7cdb796e39`  
**Current Development HEAD (`CURRENT_HEAD`)**: `75a8d011d44dcc24d97a46ac312cda7cdb796e39`  
**Branch**: `development`  
**Total Commits on Branch**: 71

## Multi-Engine Matrix & Gate Results

| Gate | Engine / Target | Result | Exit Code | Evidence Log |
|---|---|---|---|---|
| Composer Validation | Strict JSON Schema | **PASS** | 0 | `reports/runs/run_20261022_090800_295758/composer_validate.log` |
| Security Audit | Dependencies (Unsuppressed) | **PASS** | 0 | `reports/runs/run_20261022_090800_295758/composer_audit.log` |
| Pint Code Style | PSR-12 / Laravel Standards | **PASS** | 0 | `reports/runs/run_20261022_090800_295758/pint.log` |
| Static Analysis | PHPStan Level 5 | **PASS** | 0 | `reports/runs/run_20261022_090800_295758/phpstan.log` |
| Unit & Feature Tests | SQLite In-Memory (3.54.0) | **PASS** | 0 | `reports/runs/run_20261022_090800_295758/phpunit_sqlite.log` |
| Integration Tests | MySQL Isolated Service (8.4.2) | **PASS** | 0 | `reports/runs/run_20261022_090800_295758/phpunit_mysql.log` |
| Integration Tests | MariaDB Isolated Service (13.0.2-MariaDB) | **PASS** | 0 | `reports/runs/run_20261022_090800_295758/phpunit_mariadb.log` |
| Integration Tests | PostgreSQL Service (18.6) | **PASS** | 0 | `reports/runs/run_20261022_090800_295758/phpunit_pgsql.log` |
| Application Integration | Laravel 11 (v11.57.0) | **PASS** | 0 | `reports/runs/run_20261022_090800_295758/laravel11_integration.log` |
| Application Integration | Laravel 12 (v12.70.1) | **PASS** | 0 | `reports/runs/run_20261022_090800_295758/laravel12_integration.log` |
| Application Integration | Laravel 13 (v13.38.0) | **PASS** | 0 | `reports/runs/run_20261022_090800_295758/laravel13_integration.log` |

## Exact Multi-Database PHPUnit Assertion & Skip Analysis

Assertion counts differ across engines due to driver-specific behavior and SQL standard compliance:

| Database Engine | Tests | Total Assertions | Skipped | Failures | Exact Assertion Breakdown |
|---|---|---|---|---|---|
| **SQLite** | 149 | **492** | 0 | 0 | **Baseline (492 assertions)**: Executes all 149 tests completely without any driver-specific skips. |
| **MySQL** | 149 | **488** | 2 | 0 | **488 assertions (-4 vs baseline)**: <br>1. `IndexHealthAuditorTest::test_it_detects_unindexed_foreign_keys` (-3 assertions): InnoDB automatically creates backing indexes on foreign keys upon constraint creation. <br>2. `QueryAnalyzerTest::test_it_permits_double_quoted_identifiers` (-1 assertion): Standard MySQL requires backticks for identifiers; double quotes are string literals outside `ANSI_QUOTES` mode. |
| **MariaDB** | 149 | **488** | 2 | 0 | **488 assertions (-4 vs baseline)**: Identical to MySQL InnoDB behavior (-3 assertions on foreign key indexing, -1 assertion on double quote identifier syntax). |
| **PostgreSQL** | 149 | **491** | 1 | 0 | **491 assertions (-1 vs baseline)**: <br>1. `QueryAnalyzerTest::test_it_permits_backtick_quoted_identifiers` (-1 assertion): PostgreSQL strictly enforces ANSI SQL standard double quotes (`"col"`) and rejects MySQL backtick syntax (`` `col` ``). |
