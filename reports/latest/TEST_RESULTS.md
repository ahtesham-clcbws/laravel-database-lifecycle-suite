# Local Test Execution Results

**Execution Run ID**: `run_20261022_090800_295758`  
**Run Start**: 2026-10-22 09:08:00 UTC  
**Run End**: 2026-10-22 09:12:30 UTC  
**Overall Status**: **PASS**  
**Certified Source Commit (`SOURCE_SHA`)**: `fecea8d4ff2f849b088c9cb6244bd5d1417039b0`  
**Certified Evidence Commit (`CERTIFIED_EVIDENCE_SHA`)**: `e13a2e4fd035c6761b1f88e84ae6708db377b4ea`  
**Current Release Candidate Commit (`CURRENT_RELEASE_CANDIDATE_SHA`)**: `37a6231ce47511253b53cbbb409e617378cea438`  
**Branch**: `development`  
**Total Commits on Branch**: 70

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

## PHPUnit Test Metrics Breakdown

| Database Engine | Tests | Assertions | Skipped | Failures | Status |
|---|---|---|---|---|---|
| **SQLite** | 149 | 492 | 0 | 0 | **PASS** |
| **MySQL** | 149 | 488 | 2 | 0 | **PASS** |
| **MariaDB** | 149 | 488 | 2 | 0 | **PASS** |
| **PostgreSQL** | 149 | 491 | 1 | 0 | **PASS** |

## Intentional Engine-Specific Skipped Tests Investigation

All skipped tests have been investigated and verified to be intentional driver syntax or database engine behavioral variations:

1. **MySQL / MariaDB (`2 skipped tests`)**:
   - `tests/IndexHealthAuditorTest.php::test_it_detects_unindexed_foreign_keys`: MySQL and MariaDB InnoDB automatically and natively create backing index structures for foreign key constraints upon creation. Testing detection of an "unindexed" foreign key is impossible because the database engine creates the index implicitly.
   - `tests/QueryAnalyzerTest.php::test_it_permits_double_quoted_identifiers`: MySQL and MariaDB treat double quotes as string literal delimiters rather than identifier quotes unless `ANSI_QUOTES` mode is configured. Standard identifiers in MySQL use backticks.
2. **PostgreSQL (`1 skipped test`)**:
   - `tests/QueryAnalyzerTest.php::test_it_permits_backtick_quoted_identifiers`: PostgreSQL adheres strictly to ANSI SQL standard double quotes (`"id"`) and does not support MySQL-style backtick identifier quoting (`` `id` ``).
