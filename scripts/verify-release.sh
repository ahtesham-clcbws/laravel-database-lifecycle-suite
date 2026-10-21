#!/usr/bin/env bash
# ==============================================================================
# Laravel Database Lifecycle Suite - Comprehensive Release Verification Runner
# ==============================================================================
# Orchestrates all quality gates, dependency audits, static analysis, unit tests,
# PostgreSQL integration tests, fresh Laravel application scenarios, and
# report generation.
# ==============================================================================

set -eo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
ROOT_DIR="$(cd "${SCRIPT_DIR}/.." && pwd)"
cd "${ROOT_DIR}"

RUN_ID="run_$(date -u +"%Y%m%d_%H%M%S")"
RUN_DIR="${ROOT_DIR}/reports/runs/${RUN_ID}"
LATEST_DIR="${ROOT_DIR}/reports/latest"
START_TIMESTAMP=$(date -u +"%Y-%m-%d %H:%M:%S UTC")

mkdir -p "${RUN_DIR}" "${LATEST_DIR}"

echo "======================================================================"
echo " Starting Full Comprehensive Release Verification & Certification"
echo " Run ID: ${RUN_ID}"
echo " Start Time: ${START_TIMESTAMP}"
echo " Root Directory: ${ROOT_DIR}"
echo "======================================================================"

# 1. System & Runtime Environment
PHP_VERSION=$(php -v | head -n 1)
COMPOSER_VERSION=$(composer --version | head -n 1)
GIT_COMMIT=$(git rev-parse HEAD)
GIT_BRANCH=$(git rev-parse --abbrev-ref HEAD)
GIT_WORKTREE_CLEAN=$(git status --porcelain | wc -l)

echo "[1/8] Validating composer.json..."
composer validate --strict

echo "[2/8] Executing Composer Security Audit..."
composer audit

echo "[3/8] Running Pint Code Style Verification..."
vendor/bin/pint --test

echo "[4/8] Running PHPStan Static Analysis (Level 5)..."
vendor/bin/phpstan analyse --no-progress

echo "[5/8] Running Local PHPUnit Test Suite on SQLite..."
vendor/bin/phpunit > "${RUN_DIR}/phpunit_sqlite.log" 2>&1
cat "${RUN_DIR}/phpunit_sqlite.log"

echo "[6/8] Running PostgreSQL 18 Multi-Engine Integration Tests..."
PGSQL_STATUS="SKIPPED"
if psql -U "${DB_USERNAME:-ahtesham}" -h 127.0.0.1 -d lifecycle_test -c "SELECT 1;" >/dev/null 2>&1; then
    DB_CONNECTION=pgsql DB_HOST=127.0.0.1 DB_PORT=5432 DB_DATABASE=lifecycle_test DB_SOURCE_DATABASE=lifecycle_source_test DB_USERNAME="${DB_USERNAME:-ahtesham}" DB_PASSWORD="" vendor/bin/phpunit > "${RUN_DIR}/phpunit_pgsql.log" 2>&1
    cat "${RUN_DIR}/phpunit_pgsql.log"
    PGSQL_STATUS="PASS"
else
    echo "PostgreSQL not available locally, skipping local PGSQL integration."
fi

echo "[7/8] Running Real Laravel Application Integration Scenarios..."
bash "${ROOT_DIR}/tests/integration-app/scripts/run-app-integration.sh" > "${RUN_DIR}/app_integration.log" 2>&1
cat "${RUN_DIR}/app_integration.log"

echo "[8/8] Generating Final Evidence Manifest and Reports..."
END_TIMESTAMP=$(date -u +"%Y-%m-%d %H:%M:%S UTC")

cat <<EOF > "${RUN_DIR}/manifest.json"
{
  "run_id": "${RUN_ID}",
  "start_time": "${START_TIMESTAMP}",
  "end_time": "${END_TIMESTAMP}",
  "git": {
    "commit": "${GIT_COMMIT}",
    "branch": "${GIT_BRANCH}",
    "worktree_clean": $((GIT_WORKTREE_CLEAN == 0))
  },
  "runtime": {
    "php": "${PHP_VERSION}",
    "composer": "${COMPOSER_VERSION}"
  },
  "results": {
    "composer_validate": "PASS",
    "composer_audit": "PASS",
    "pint": "PASS",
    "phpstan": "PASS",
    "phpunit_sqlite": "PASS",
    "phpunit_pgsql": "${PGSQL_STATUS}",
    "app_integration": "PASS"
  }
}
EOF

cp "${RUN_DIR}/manifest.json" "${LATEST_DIR}/manifest.json"

cat <<EOF > "${LATEST_DIR}/TEST_RESULTS.md"
# Local Test Execution Results

**Execution Run ID**: \`${RUN_ID}\`  
**Run Start**: ${START_TIMESTAMP}  
**Run End**: ${END_TIMESTAMP}  
**Commit**: \`${GIT_COMMIT}\`  
**Branch**: \`${GIT_BRANCH}\`

## Test Suites Output

### 1. SQLite Suite
\`\`\`
$(cat "${RUN_DIR}/phpunit_sqlite.log")
\`\`\`

### 2. PostgreSQL 18 Integration Suite
\`\`\`
$(cat "${RUN_DIR}/phpunit_pgsql.log")
\`\`\`

### 3. Static Analysis (PHPStan)
\`\`\`
$(vendor/bin/phpstan analyse --no-progress)
\`\`\`
EOF

echo "======================================================================"
echo " All Quality Gates & Real App Scenarios Passed Successfully!"
echo " Evidence persisted to: reports/latest/ and reports/runs/${RUN_ID}/"
echo "======================================================================"
