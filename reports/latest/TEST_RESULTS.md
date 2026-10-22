# Local Test Execution Results

**Execution Run ID**: `run_20261022_075429_275280`  
**Run Start**: 2026-10-22 07:54:29 UTC  
**Run End**: 2026-10-22 07:58:53 UTC  
**Overall Status**: **PASS**  
**Certified Source Commit (`SOURCE_SHA`)**: `fecea8d4ff2f849b088c9cb6244bd5d1417039b0`  
**Certified Evidence Commit (`CERTIFIED_EVIDENCE_SHA`)**: `b4af13da7869434b91e7e6e5036c5b6ed60f127a`  
**Current Release Candidate Commit (`CURRENT_RELEASE_CANDIDATE_SHA`)**: `c01bf76a6869410afef544fc883d2aad70732e34`  
**Branch**: `development`  
**Total Commits on Branch**: 69

## Multi-Engine Matrix & Gate Results

| Gate | Engine / Target | Result | Exit Code | Evidence Log |
|---|---|---|---|---|
| Composer Validation | Strict JSON Schema | **PASS** | 0 | `reports/runs/run_20261022_075429_275280/composer_validate.log` |
| Security Audit | Dependencies (Unsuppressed) | **PASS** | 0 | `reports/runs/run_20261022_075429_275280/composer_audit.log` |
| Pint Code Style | PSR-12 / Laravel Standards | **PASS** | 0 | `reports/runs/run_20261022_075429_275280/pint.log` |
| Static Analysis | PHPStan Level 5 | **PASS** | 0 | `reports/runs/run_20261022_075429_275280/phpstan.log` |
| Unit & Feature Tests | SQLite In-Memory (3.54.0) | **PASS** | 0 | `reports/runs/run_20261022_075429_275280/phpunit_sqlite.log` |
| Integration Tests | MySQL Isolated Service (8.4.2) | **PASS** | 0 | `reports/runs/run_20261022_075429_275280/phpunit_mysql.log` |
| Integration Tests | MariaDB Isolated Service (13.0.2-MariaDB) | **PASS** | 0 | `reports/runs/run_20261022_075429_275280/phpunit_mariadb.log` |
| Integration Tests | PostgreSQL Service (18.6) | **PASS** | 0 | `reports/runs/run_20261022_075429_275280/phpunit_pgsql.log` |
| Application Integration | Laravel 11 (v11.57.0) | **PASS** | 0 | `reports/runs/run_20261022_075429_275280/laravel11_integration.log` |
| Application Integration | Laravel 12 (v12.70.1) | **PASS** | 0 | `reports/runs/run_20261022_075429_275280/laravel12_integration.log` |
| Application Integration | Laravel 13 (v13.38.0) | **PASS** | 0 | `reports/runs/run_20261022_075429_275280/laravel13_integration.log` |

## PHPUnit Test Metrics Breakdown

| Database Engine | Tests | Assertions | Skipped | Failures | Status |
|---|---|---|---|---|---|
| **SQLite** | 149 | 492 | 0 | 0 | **PASS** |
| **MySQL** | 149 | 488 | 2 | 0 | **PASS** |
| **MariaDB** | 149 | 488 | 2 | 0 | **PASS** |
| **PostgreSQL** | 149 | 491 | 1 | 0 | **PASS** |
