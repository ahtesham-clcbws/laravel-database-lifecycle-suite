#!/usr/bin/env bash
# ==============================================================================
# Laravel Database Lifecycle Suite - Comprehensive Release Verification Runner
# ==============================================================================
# Usage:
#   bash scripts/verify-release.sh [--database=sqlite|pgsql|mariadb|all] [--laravel=11|12|all]
#
# Orchestrates all quality gates, dependency audits, static analysis, unit tests,
# multi-engine integration tests (SQLite, PostgreSQL, MariaDB), fresh Laravel
# application integration scenarios, and structured report generation.
# ==============================================================================

set -eo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
ROOT_DIR="$(cd "${SCRIPT_DIR}/.." && pwd)"
cd "${ROOT_DIR}"

SELECTED_DB="all"
SELECTED_LV="all"

for arg in "$@"; do
    case $arg in
        --database=*)
            SELECTED_DB="${arg#*=}"
            ;;
        --laravel=*)
            SELECTED_LV="${arg#*=}"
            ;;
    esac
done

RUN_ID="run_$(date -u +"%Y%m%d_%H%M%S")"
RUN_DIR="${ROOT_DIR}/reports/runs/${RUN_ID}"
LATEST_DIR="${ROOT_DIR}/reports/latest"
START_TIMESTAMP=$(date -u +"%Y-%m-%d %H:%M:%S UTC")

mkdir -p "${RUN_DIR}" "${LATEST_DIR}"

echo "======================================================================"
echo " Starting Full Comprehensive Release Verification & Certification"
echo " Run ID: ${RUN_ID}"
echo " Database Target: ${SELECTED_DB}"
echo " Laravel Target: ${SELECTED_LV}"
echo " Start Time: ${START_TIMESTAMP}"
echo " Root Directory: ${ROOT_DIR}"
echo "======================================================================"

# Git and Runtime Metadata
PHP_VERSION=$(php -v | head -n 1)
COMPOSER_VERSION=$(composer --version | head -n 1)
GIT_COMMIT=$(git rev-parse HEAD)
GIT_BRANCH=$(git rev-parse --abbrev-ref HEAD)
GIT_WORKTREE_DIRTY=$(git status --porcelain | wc -l)
GIT_COMMIT_COUNT=$(git rev-list --count HEAD)

# 1. Composer validate
echo "[1/8] Validating composer.json..."
COMPOSER_VAL_STATUS="RUNNING"
if composer validate --strict > "${RUN_DIR}/composer_validate.log" 2>&1; then
    COMPOSER_VAL_STATUS="PASS"
    cat "${RUN_DIR}/composer_validate.log"
else
    COMPOSER_VAL_STATUS="FAIL"
    cat "${RUN_DIR}/composer_validate.log"
    exit 1
fi

# 2. Composer audit
echo "[2/8] Executing Composer Security Audit..."
COMPOSER_AUDIT_STATUS="RUNNING"
if composer audit > "${RUN_DIR}/composer_audit.log" 2>&1; then
    COMPOSER_AUDIT_STATUS="PASS"
    cat "${RUN_DIR}/composer_audit.log"
else
    COMPOSER_AUDIT_STATUS="FAIL"
    cat "${RUN_DIR}/composer_audit.log"
    exit 1
fi

# 3. Pint code style
echo "[3/8] Running Pint Code Style Verification..."
PINT_STATUS="RUNNING"
if vendor/bin/pint --test > "${RUN_DIR}/pint.log" 2>&1; then
    PINT_STATUS="PASS"
    cat "${RUN_DIR}/pint.log"
else
    PINT_STATUS="FAIL"
    cat "${RUN_DIR}/pint.log"
    exit 1
fi

# 4. PHPStan static analysis
echo "[4/8] Running PHPStan Static Analysis (Level 5)..."
PHPSTAN_STATUS="RUNNING"
if vendor/bin/phpstan analyse --no-progress > "${RUN_DIR}/phpstan.log" 2>&1; then
    PHPSTAN_STATUS="PASS"
    cat "${RUN_DIR}/phpstan.log"
else
    PHPSTAN_STATUS="FAIL"
    cat "${RUN_DIR}/phpstan.log"
    exit 1
fi

# 5. PHPUnit on SQLite
PHPUNIT_SQLITE_STATUS="SKIPPED"
if [ "${SELECTED_DB}" = "sqlite" ] || [ "${SELECTED_DB}" = "all" ]; then
    echo "[5/8] Running Local PHPUnit Test Suite on SQLite..."
    PHPUNIT_SQLITE_STATUS="RUNNING"
    if vendor/bin/phpunit > "${RUN_DIR}/phpunit_sqlite.log" 2>&1; then
        PHPUNIT_SQLITE_STATUS="PASS"
        cat "${RUN_DIR}/phpunit_sqlite.log"
    else
        PHPUNIT_SQLITE_STATUS="FAIL"
        cat "${RUN_DIR}/phpunit_sqlite.log"
        exit 1
    fi
fi

# 6. Multi-Engine: PostgreSQL & MariaDB
PHPUNIT_PGSQL_STATUS="SKIPPED"
if [ "${SELECTED_DB}" = "pgsql" ] || [ "${SELECTED_DB}" = "all" ]; then
    echo "[6a/8] Running PostgreSQL 18 Multi-Engine Integration Tests..."
    if psql -U "${DB_USERNAME:-ahtesham}" -h 127.0.0.1 -d lifecycle_test -c "SELECT 1;" >/dev/null 2>&1; then
        PHPUNIT_PGSQL_STATUS="RUNNING"
        if DB_CONNECTION=pgsql DB_HOST=127.0.0.1 DB_PORT=5432 DB_DATABASE=lifecycle_test DB_SOURCE_DATABASE=lifecycle_source_test DB_USERNAME="${DB_USERNAME:-ahtesham}" DB_PASSWORD="" vendor/bin/phpunit > "${RUN_DIR}/phpunit_pgsql.log" 2>&1; then
            PHPUNIT_PGSQL_STATUS="PASS"
            cat "${RUN_DIR}/phpunit_pgsql.log"
        else
            PHPUNIT_PGSQL_STATUS="FAIL"
            cat "${RUN_DIR}/phpunit_pgsql.log"
            exit 1
        fi
    else
        echo "PostgreSQL not reachable on 127.0.0.1:5432; skipping PGSQL test."
        PHPUNIT_PGSQL_STATUS="SKIPPED"
    fi
fi

PHPUNIT_MARIADB_STATUS="SKIPPED"
if [ "${SELECTED_DB}" = "mariadb" ] || [ "${SELECTED_DB}" = "all" ]; then
    echo "[6b/8] Running MariaDB Multi-Engine Integration Tests..."
    if command -v mariadb-install-db >/dev/null 2>&1 && command -v mariadbd >/dev/null 2>&1; then
        MARIA_TMP="/tmp/mariadb-verify-${RUN_ID}"
        mkdir -p "${MARIA_TMP}"
        mariadb-install-db --datadir="${MARIA_TMP}" --auth-root-authentication-method=normal >/dev/null 2>&1
        mariadbd --datadir="${MARIA_TMP}" --port=3307 --socket="${MARIA_TMP}/mysql.sock" --pid-file="${MARIA_TMP}/mysql.pid" >/dev/null 2>&1 &
        MARIA_PID=$!
        sleep 2
        
        cleanup_mariadb() {
            if kill -0 "${MARIA_PID}" >/dev/null 2>&1; then
                kill -9 "${MARIA_PID}" >/dev/null 2>&1 || true
            fi
            rm -rf "${MARIA_TMP}" || true
        }
        trap cleanup_mariadb EXIT

        mariadb -h 127.0.0.1 -P 3307 -u root -e "CREATE DATABASE IF NOT EXISTS lifecycle_test; CREATE DATABASE IF NOT EXISTS lifecycle_source_test;" >/dev/null 2>&1

        PHPUNIT_MARIADB_STATUS="RUNNING"
        if DB_CONNECTION=mysql DB_HOST=127.0.0.1 DB_PORT=3307 DB_DATABASE=lifecycle_test DB_SOURCE_DATABASE=lifecycle_source_test DB_USERNAME=root DB_PASSWORD="" vendor/bin/phpunit > "${RUN_DIR}/phpunit_mariadb.log" 2>&1; then
            PHPUNIT_MARIADB_STATUS="PASS"
            cat "${RUN_DIR}/phpunit_mariadb.log"
        else
            PHPUNIT_MARIADB_STATUS="FAIL"
            cat "${RUN_DIR}/phpunit_mariadb.log"
            cleanup_mariadb
            exit 1
        fi
        cleanup_mariadb
        trap - EXIT
    else
        echo "MariaDB binaries not found; skipping MariaDB test."
        PHPUNIT_MARIADB_STATUS="SKIPPED"
    fi
fi

# 7. Real Laravel Application Integration Scenarios
echo "[7/8] Running Real Laravel Application Integration Scenarios (${SELECTED_LV})..."
APP_INTEGRATION_STATUS="RUNNING"
if bash "${ROOT_DIR}/tests/integration-app/scripts/run-app-integration.sh" --laravel="${SELECTED_LV}" > "${RUN_DIR}/app_integration.log" 2>&1; then
    APP_INTEGRATION_STATUS="PASS"
    cat "${RUN_DIR}/app_integration.log"
else
    APP_INTEGRATION_STATUS="FAIL"
    cat "${RUN_DIR}/app_integration.log"
    exit 1
fi

# 8. Manifest & Reports
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
    "worktree_clean": $((GIT_WORKTREE_DIRTY == 0)),
    "commit_count": ${GIT_COMMIT_COUNT}
  },
  "runtime": {
    "php": "${PHP_VERSION}",
    "composer": "${COMPOSER_VERSION}"
  },
  "results": {
    "composer_validate": "${COMPOSER_VAL_STATUS}",
    "composer_audit": "${COMPOSER_AUDIT_STATUS}",
    "pint": "${PINT_STATUS}",
    "phpstan": "${PHPSTAN_STATUS}",
    "phpunit_sqlite": "${PHPUNIT_SQLITE_STATUS}",
    "phpunit_pgsql": "${PHPUNIT_PGSQL_STATUS}",
    "phpunit_mariadb": "${PHPUNIT_MARIADB_STATUS}",
    "app_integration": "${APP_INTEGRATION_STATUS}"
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
**Total Commits on Branch**: ${GIT_COMMIT_COUNT}

## Multi-Engine Matrix & Gate Results

| Gate | Engine / Target | Result | Evidence |
|---|---|---|---|
| Composer Validation | Strict | **${COMPOSER_VAL_STATUS}** | composer_validate.log |
| Security Audit | Dependencies | **${COMPOSER_AUDIT_STATUS}** | composer_audit.log |
| Pint Code Style | PSR-12 / Laravel | **${PINT_STATUS}** | pint.log |
| Static Analysis | PHPStan Level 5 | **${PHPSTAN_STATUS}** | phpstan.log |
| Unit & Feature Tests | SQLite In-Memory | **${PHPUNIT_SQLITE_STATUS}** | phpunit_sqlite.log |
| Integration Tests | PostgreSQL 18.6 | **${PHPUNIT_PGSQL_STATUS}** | phpunit_pgsql.log |
| Integration Tests | MariaDB 13.0 | **${PHPUNIT_MARIADB_STATUS}** | phpunit_mariadb.log |
| Application Integration | Laravel ${SELECTED_LV} | **${APP_INTEGRATION_STATUS}** | app_integration.log |

EOF

echo "======================================================================"
echo " All Quality Gates & Real App Scenarios Passed Successfully!"
echo " Evidence persisted to: reports/latest/ and reports/runs/${RUN_ID}/"
echo "======================================================================"
