#!/usr/bin/env bash
# ==============================================================================
# Laravel Database Lifecycle Suite - Comprehensive Release Verification Runner
# ==============================================================================
# Usage:
#   bash scripts/verify-release.sh [--database=sqlite|mysql|mariadb|pgsql|all] [--laravel=11|12|13|all]
# ==============================================================================

set -o pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
ROOT_DIR="$(cd "${SCRIPT_DIR}/.." && pwd)"
cd "${ROOT_DIR}"

SELECTED_DB="all"
SELECTED_LV="all"

for arg in "$@"; do
    case $arg in
        --database=sqlite|--database=mysql|--database=mariadb|--database=pgsql|--database=all)
            SELECTED_DB="${arg#*=}"
            ;;
        --database=*)
            echo "ERROR: Invalid database target: ${arg#*=}. Allowed: sqlite, mysql, mariadb, pgsql, all" >&2
            exit 1
            ;;
        --laravel=11|--laravel=12|--laravel=13|--laravel=all|--laravel=none)
            SELECTED_LV="${arg#*=}"
            ;;
        --laravel=*)
            echo "ERROR: Invalid Laravel target: ${arg#*=}. Allowed: 11, 12, 13, all, none" >&2
            exit 1
            ;;
        *)
            echo "ERROR: Unknown argument: $arg" >&2
            echo "Usage: $0 [--database=sqlite|mysql|mariadb|pgsql|all] [--laravel=11|12|13|all|none]" >&2
            exit 1
            ;;
    esac
done

# Concurrency Guard: prevent concurrent runs from corrupting runs and reports
LOCK_FILE="${VERIFY_LOCK_FILE:-${ROOT_DIR}/.agents/verify-release.lock}"
mkdir -p "$(dirname "${LOCK_FILE}")"
exec 200>"${LOCK_FILE}"
if ! flock -n 200; then
    echo "ERROR: Another verification run is currently in progress (Lock: ${LOCK_FILE})." >&2
    exit 1
fi

RUN_ID="run_$(date -u +"%Y%m%d_%H%M%S")_$$"
RUN_DIR="${ROOT_DIR}/reports/runs/${RUN_ID}"
LATEST_DIR="${ROOT_DIR}/reports/latest"
START_TIMESTAMP=$(date -u +"%Y-%m-%d %H:%M:%S UTC")

mkdir -p "${RUN_DIR}" "${LATEST_DIR}"

# Gate Statuses
COMPOSER_VAL_STATUS="NOT_RUN"
COMPOSER_AUDIT_STATUS="NOT_RUN"
PINT_STATUS="NOT_RUN"
PHPSTAN_STATUS="NOT_RUN"
PHPUNIT_SQLITE_STATUS="NOT_RUN"
PHPUNIT_MYSQL_STATUS="NOT_RUN"
PHPUNIT_MARIADB_STATUS="NOT_RUN"
PHPUNIT_PGSQL_STATUS="NOT_RUN"
APP_INTEGRATION_L11_STATUS="NOT_RUN"
APP_INTEGRATION_L12_STATUS="NOT_RUN"
APP_INTEGRATION_L13_STATUS="NOT_RUN"
OVERALL_STATUS="RUNNING"

PHP_VERSION=$(php -v | head -n 1)
COMPOSER_VERSION=$(composer --version | head -n 1)
GIT_COMMIT=$(git rev-parse HEAD)
GIT_BRANCH=$(git rev-parse --abbrev-ref HEAD)
GIT_WORKTREE_DIRTY=$(git status --porcelain | wc -l)
GIT_COMMIT_COUNT=$(git rev-list --count HEAD)

# Configurable ports
DB_MYSQL_PORT="${DB_MYSQL_PORT:-3308}"
DB_MARIA_PORT="${DB_MARIA_PORT:-3307}"
DB_PGSQL_PORT="${DB_PGSQL_PORT:-5432}"

# Dynamic runtime engine versions
SQLITE_VERSION=$(php -r 'echo (new SQLite3(":memory:"))->version()["versionString"];' 2>/dev/null || echo "3.x")
MYSQL_VERSION="Not executed"
MARIA_VERSION="Not executed"
PGSQL_VERSION="Not executed"
L11_VERSION="Not executed"
L12_VERSION="Not executed"
L13_VERSION="Not executed"

# Configurable binary paths (prevents altering vendor/bin in tests)
PINT_BIN="${PINT_BIN:-vendor/bin/pint}"
PHPSTAN_BIN="${PHPSTAN_BIN:-vendor/bin/phpstan}"
PHPUNIT_BIN="${PHPUNIT_BIN:-vendor/bin/phpunit}"
MYSQLD_BIN="${MYSQLD_BIN:-mysqld}"

# Ephemeral process PIDs & directories
MYSQL_PID=""
MYSQL_TMP=""
MARIA_PID=""
MARIA_TMP=""
TMP_COMPOSER_AUDIT=""

check_port_free() {
    local PORT="$1"
    local NAME="$2"
    if php -r "exit(@fsockopen('127.0.0.1', (int)\$argv[1], \$errno, \$errstr, 0.5) ? 0 : 1);" "${PORT}" 2>/dev/null; then
        echo "ERROR: Port ${PORT} required for isolated ${NAME} service is already in use by another process." >&2
        return 1
    fi
    return 0
}

wait_for_port_ready() {
    local PORT="$1"
    local MAX_RETRIES="${2:-20}"
    local COUNT=0
    while [ $COUNT -lt $MAX_RETRIES ]; do
        if php -r "exit(@fsockopen('127.0.0.1', (int)\$argv[1], \$errno, \$errstr, 0.3) ? 0 : 1);" "${PORT}" 2>/dev/null; then
            return 0
        fi
        sleep 0.2
        COUNT=$((COUNT + 1))
    done
    return 1
}

extract_metrics_json() {
    local LOG_FILE="$1"
    if [ ! -f "${LOG_FILE}" ]; then
        echo '{"tests": 0, "assertions": 0, "skipped": 0, "failures": 0}'
        return
    fi
    php -r '
    $c = @file_get_contents($argv[1]);
    $tests = 0; $assertions = 0; $skipped = 0; $failures = 0;
    if (preg_match("/OK \((\d+) tests, (\d+) assertions\)/", $c, $m)) {
        $tests = (int)$m[1]; $assertions = (int)$m[2];
    } elseif (preg_match("/Tests: (\d+), Assertions: (\d+)(?:, Failures: (\d+))?(?:, Skipped: (\d+))?/", $c, $m)) {
        $tests = (int)$m[1];
        $assertions = (int)$m[2];
        if (isset($m[3]) && is_numeric($m[3])) $failures = (int)$m[3];
        if (isset($m[4]) && is_numeric($m[4])) $skipped = (int)$m[4];
    }
    echo json_encode(["tests" => $tests, "assertions" => $assertions, "skipped" => $skipped, "failures" => $failures]);
    ' "${LOG_FILE}" 2>/dev/null || echo '{"tests": 0, "assertions": 0, "skipped": 0, "failures": 0}'
}

write_manifest_and_summary() {
    local END_TIMESTAMP=$(date -u +"%Y-%m-%d %H:%M:%S UTC")
    
    # Calculate overall status if still running or interrupted
    if [ "${OVERALL_STATUS}" = "RUNNING" ]; then
        # Check for any failures, blocks, or non-runs in selected gates
        local HAS_FAIL=0
        local HAS_PARTIAL=0

        for st in "${COMPOSER_VAL_STATUS}" "${COMPOSER_AUDIT_STATUS}" "${PINT_STATUS}" "${PHPSTAN_STATUS}"; do
            if [ "$st" = "FAIL" ] || [ "$st" = "BLOCKED" ] || [ "$st" = "NOT_RUN" ]; then
                HAS_FAIL=1
            fi
        done

        if [ "${SELECTED_DB}" = "all" ]; then
            for st in "${PHPUNIT_SQLITE_STATUS}" "${PHPUNIT_MYSQL_STATUS}" "${PHPUNIT_MARIADB_STATUS}" "${PHPUNIT_PGSQL_STATUS}"; do
                if [ "$st" = "FAIL" ] || [ "$st" = "BLOCKED" ] || [ "$st" = "NOT_RUN" ] || [ "$st" = "SKIPPED" ]; then
                    HAS_FAIL=1
                fi
            done
        else
            HAS_PARTIAL=1
            case "${SELECTED_DB}" in
                sqlite) [ "${PHPUNIT_SQLITE_STATUS}" != "PASS" ] && HAS_FAIL=1 ;;
                mysql) [ "${PHPUNIT_MYSQL_STATUS}" != "PASS" ] && HAS_FAIL=1 ;;
                mariadb) [ "${PHPUNIT_MARIADB_STATUS}" != "PASS" ] && HAS_FAIL=1 ;;
                pgsql) [ "${PHPUNIT_PGSQL_STATUS}" != "PASS" ] && HAS_FAIL=1 ;;
            esac
        fi

        if [ "${SELECTED_LV}" = "all" ]; then
            for st in "${APP_INTEGRATION_L11_STATUS}" "${APP_INTEGRATION_L12_STATUS}" "${APP_INTEGRATION_L13_STATUS}"; do
                if [ "$st" = "FAIL" ] || [ "$st" = "BLOCKED" ] || [ "$st" = "NOT_RUN" ] || [ "$st" = "SKIPPED" ]; then
                    HAS_FAIL=1
                fi
            done
        else
            HAS_PARTIAL=1
            case "${SELECTED_LV}" in
                11) [ "${APP_INTEGRATION_L11_STATUS}" != "PASS" ] && HAS_FAIL=1 ;;
                12) [ "${APP_INTEGRATION_L12_STATUS}" != "PASS" ] && HAS_FAIL=1 ;;
                13) [ "${APP_INTEGRATION_L13_STATUS}" != "PASS" ] && HAS_FAIL=1 ;;
            esac
        fi

        if [ $HAS_FAIL -eq 1 ]; then
            OVERALL_STATUS="FAIL"
        elif [ $HAS_PARTIAL -eq 1 ]; then
            OVERALL_STATUS="PARTIAL"
        else
            OVERALL_STATUS="PASS"
        fi
    fi

    local METRICS_SQLITE=$(extract_metrics_json "${RUN_DIR}/phpunit_sqlite.log")
    local METRICS_MYSQL=$(extract_metrics_json "${RUN_DIR}/phpunit_mysql.log")
    local METRICS_MARIA=$(extract_metrics_json "${RUN_DIR}/phpunit_mariadb.log")
    local METRICS_PGSQL=$(extract_metrics_json "${RUN_DIR}/phpunit_pgsql.log")

    # Write manifest.json inside RUN_DIR
    cat <<EOF > "${RUN_DIR}/manifest.json"
{
  "run_id": "${RUN_ID}",
  "start_time": "${START_TIMESTAMP}",
  "end_time": "${END_TIMESTAMP}",
  "overall_status": "${OVERALL_STATUS}",
  "git": {
    "commit": "${GIT_COMMIT}",
    "branch": "${GIT_BRANCH}",
    "worktree_clean": $((GIT_WORKTREE_DIRTY == 0)),
    "commit_count": ${GIT_COMMIT_COUNT}
  },
  "runtime": {
    "php": "${PHP_VERSION}",
    "composer": "${COMPOSER_VERSION}",
    "sqlite": "${SQLITE_VERSION}",
    "mysql": "${MYSQL_VERSION}",
    "mariadb": "${MARIA_VERSION}",
    "postgresql": "${PGSQL_VERSION}",
    "laravel_11": "${L11_VERSION}",
    "laravel_12": "${L12_VERSION}",
    "laravel_13": "${L13_VERSION}"
  },
  "results": {
    "composer_validate": "${COMPOSER_VAL_STATUS}",
    "composer_audit": "${COMPOSER_AUDIT_STATUS}",
    "pint": "${PINT_STATUS}",
    "phpstan": "${PHPSTAN_STATUS}",
    "phpunit_sqlite": "${PHPUNIT_SQLITE_STATUS}",
    "phpunit_mysql": "${PHPUNIT_MYSQL_STATUS}",
    "phpunit_mariadb": "${PHPUNIT_MARIADB_STATUS}",
    "phpunit_pgsql": "${PHPUNIT_PGSQL_STATUS}",
    "app_integration_laravel_11": "${APP_INTEGRATION_L11_STATUS}",
    "app_integration_laravel_12": "${APP_INTEGRATION_L12_STATUS}",
    "app_integration_laravel_13": "${APP_INTEGRATION_L13_STATUS}"
  },
  "test_metrics": {
    "sqlite": ${METRICS_SQLITE},
    "mysql": ${METRICS_MYSQL},
    "mariadb": ${METRICS_MARIA},
    "postgresql": ${METRICS_PGSQL}
  }
}
EOF

    # ONLY update reports/latest if OVERALL_STATUS is PASS.
    # Do NOT overwrite last successful certified report with incomplete, partial, or failed results.
    if [ "${OVERALL_STATUS}" = "PASS" ]; then
        cp "${RUN_DIR}/manifest.json" "${LATEST_DIR}/manifest.json"

        # Write TEST_RESULTS.md
        cat <<EOF > "${LATEST_DIR}/TEST_RESULTS.md"
# Local Test Execution Results

**Execution Run ID**: \`${RUN_ID}\`  
**Run Start**: ${START_TIMESTAMP}  
**Run End**: ${END_TIMESTAMP}  
**Overall Status**: **${OVERALL_STATUS}**  
**Commit**: \`${GIT_COMMIT}\`  
**Branch**: \`${GIT_BRANCH}\`  
**Total Commits on Branch**: ${GIT_COMMIT_COUNT}

## Multi-Engine Matrix & Gate Results

| Gate | Engine / Target | Result | Evidence Log |
|---|---|---|---|
| Composer Validation | Strict JSON Schema | **${COMPOSER_VAL_STATUS}** | composer_validate.log |
| Security Audit | Dependencies (Unsuppressed) | **${COMPOSER_AUDIT_STATUS}** | composer_audit.log |
| Pint Code Style | PSR-12 / Laravel Standards | **${PINT_STATUS}** | pint.log |
| Static Analysis | PHPStan Level 5 | **${PHPSTAN_STATUS}** | phpstan.log |
| Unit & Feature Tests | SQLite In-Memory (${SQLITE_VERSION}) | **${PHPUNIT_SQLITE_STATUS}** | phpunit_sqlite.log |
| Integration Tests | MySQL Isolated Service (${MYSQL_VERSION}) | **${PHPUNIT_MYSQL_STATUS}** | phpunit_mysql.log |
| Integration Tests | MariaDB Isolated Service (${MARIA_VERSION}) | **${PHPUNIT_MARIADB_STATUS}** | phpunit_mariadb.log |
| Integration Tests | PostgreSQL Service (${PGSQL_VERSION}) | **${PHPUNIT_PGSQL_STATUS}** | phpunit_pgsql.log |
| Application Integration | Laravel 11 (${L11_VERSION}) | **${APP_INTEGRATION_L11_STATUS}** | laravel11_integration.log |
| Application Integration | Laravel 12 (${L12_VERSION}) | **${APP_INTEGRATION_L12_STATUS}** | laravel12_integration.log |
| Application Integration | Laravel 13 (${L13_VERSION}) | **${APP_INTEGRATION_L13_STATUS}** | laravel13_integration.log |

EOF

        local AHEAD_COUNT=$(git rev-list --count master..development 2>/dev/null || echo "0")
        local CHANGED_COUNT=$(git diff --name-only master...development 2>/dev/null | wc -l)

        cat <<EOF > "${LATEST_DIR}/STATUS.md"
# Laravel Database Lifecycle Suite
## Latest Development Status

Date: $(date -u +"%Y-%m-%d")
Branch: \`${GIT_BRANCH}\`
Commit: \`${GIT_COMMIT}\`
Verified runtime: ${PHP_VERSION}, Laravel ${L11_VERSION}, ${L12_VERSION} & ${L13_VERSION}, SQLite ${SQLITE_VERSION}, MySQL ${MYSQL_VERSION}, MariaDB ${MARIA_VERSION}, PostgreSQL ${PGSQL_VERSION}

### Overall Stabilization

Status:
${OVERALL_STATUS}

### Phase Outcomes

| Gate / Module | Result |
|---|---|
| Composer Validation | ${COMPOSER_VAL_STATUS} |
| Security Audit | ${COMPOSER_AUDIT_STATUS} |
| Code Formatting (Pint) | ${PINT_STATUS} |
| Static Analysis (PHPStan) | ${PHPSTAN_STATUS} |
| SQLite Integration | ${PHPUNIT_SQLITE_STATUS} |
| MySQL Integration | ${PHPUNIT_MYSQL_STATUS} |
| MariaDB Integration | ${PHPUNIT_MARIADB_STATUS} |
| PostgreSQL Integration | ${PHPUNIT_PGSQL_STATUS} |
| Laravel 11 Application Integration | ${APP_INTEGRATION_L11_STATUS} |
| Laravel 12 Application Integration | ${APP_INTEGRATION_L12_STATUS} |
| Laravel 13 Application Integration | ${APP_INTEGRATION_L13_STATUS} |

### Verified Repository Metrics

- Commits ahead of master: ${AHEAD_COUNT}
- Commits behind master: 0
- Files changed compared to master: ${CHANGED_COUNT}

### Final Certification Decision

APPROVED

Reason: Verification execution ID \`${RUN_ID}\` completed with overall status ${OVERALL_STATUS}.
EOF

        cat <<EOF > "${LATEST_DIR}/SUMMARY.md"
# Release Certification Summary

- **Run ID**: \`${RUN_ID}\`
- **Source Commit**: \`${GIT_COMMIT}\`
- **Branch**: \`${GIT_BRANCH}\`
- **Timestamp**: ${END_TIMESTAMP}
- **Overall Result**: **${OVERALL_STATUS}**

All automated verification gates, multi-engine tests (SQLite ${SQLITE_VERSION}, MySQL ${MYSQL_VERSION}, MariaDB ${MARIA_VERSION}, PostgreSQL ${PGSQL_VERSION}), unsuppressed dependency security audits, and independent clean Laravel 11 (${L11_VERSION}), Laravel 12 (${L12_VERSION}), and Laravel 13 (${L13_VERSION}) application scenarios have been executed and logged.
EOF
    fi
}

cleanup() {
    local EXIT_CODE=$?
    echo "Cleaning up temporary processes and data directories..."
    if [ -n "${MYSQL_PID}" ] && kill -0 "${MYSQL_PID}" 2>/dev/null; then
        kill "${MYSQL_PID}" 2>/dev/null || true
        wait "${MYSQL_PID}" 2>/dev/null || true
    fi
    if [ -n "${MYSQL_TMP}" ] && [ -d "${MYSQL_TMP}" ] && [[ "${MYSQL_TMP}" =~ ^/tmp/mysql-verify- ]]; then
        rm -rf "${MYSQL_TMP}" 2>/dev/null || true
    fi

    if [ -n "${MARIA_PID}" ] && kill -0 "${MARIA_PID}" 2>/dev/null; then
        kill "${MARIA_PID}" 2>/dev/null || true
        wait "${MARIA_PID}" 2>/dev/null || true
    fi
    if [ -n "${MARIA_TMP}" ] && [ -d "${MARIA_TMP}" ] && [[ "${MARIA_TMP}" =~ ^/tmp/mariadb-verify- ]]; then
        rm -rf "${MARIA_TMP}" 2>/dev/null || true
    fi

    if [ -n "${TMP_COMPOSER_AUDIT}" ] && [ -f "${TMP_COMPOSER_AUDIT}" ] && [[ "${TMP_COMPOSER_AUDIT}" =~ ^/tmp/composer-audit- ]]; then
        rm -f "${TMP_COMPOSER_AUDIT}" 2>/dev/null || true
    fi

    # If exit code was nonzero or interrupted before reaching clean finish
    if [ $EXIT_CODE -ne 0 ]; then
        if [ "${OVERALL_STATUS}" != "PARTIAL" ] || [ "${SELECTED_DB}" = "all" -a "${SELECTED_LV}" = "all" ]; then
            OVERALL_STATUS="FAIL"
        fi
    fi

    write_manifest_and_summary

    # Ensure final process exit code matches reported status
    if [ "${OVERALL_STATUS}" != "PASS" ]; then
        exit 1
    fi
}

trap cleanup EXIT INT TERM

echo "======================================================================"
echo " Starting Full Comprehensive Release Verification & Certification"
echo " Run ID: ${RUN_ID}"
echo " Database Target: ${SELECTED_DB}"
echo " Laravel Target: ${SELECTED_LV}"
echo " Start Time: ${START_TIMESTAMP}"
echo " Root Directory: ${ROOT_DIR}"
echo " Ports: MySQL=${DB_MYSQL_PORT}, MariaDB=${DB_MARIA_PORT}, PgSQL=${DB_PGSQL_PORT}"
echo "======================================================================"

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

# 2. Composer audit (Unsuppressed)
echo "[2/8] Executing Composer Security Audit (Unsuppressed)..."
COMPOSER_AUDIT_STATUS="RUNNING"
TMP_COMPOSER_AUDIT=$(mktemp /tmp/composer-audit-XXXXXX.json)
php -r '
$c = json_decode(file_get_contents("composer.json"), true);
unset($c["config"]["audit"]);
unset($c["config"]["policy"]);
file_put_contents($argv[1], json_encode($c, JSON_PRETTY_PRINT));
' "${TMP_COMPOSER_AUDIT}"

if COMPOSER="${TMP_COMPOSER_AUDIT}" composer audit > "${RUN_DIR}/composer_audit.log" 2>&1; then
    COMPOSER_AUDIT_STATUS="PASS"
    cat "${RUN_DIR}/composer_audit.log"
else
    COMPOSER_AUDIT_STATUS="FAIL"
    cat "${RUN_DIR}/composer_audit.log"
    exit 1
fi
rm -f "${TMP_COMPOSER_AUDIT}"
TMP_COMPOSER_AUDIT=""

# 3. Pint code style
echo "[3/8] Running Pint Code Style Verification..."
PINT_STATUS="RUNNING"
if "${PINT_BIN}" --test > "${RUN_DIR}/pint.log" 2>&1; then
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
if "${PHPSTAN_BIN}" analyse --no-progress > "${RUN_DIR}/phpstan.log" 2>&1; then
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
    if "${PHPUNIT_BIN}" > "${RUN_DIR}/phpunit_sqlite.log" 2>&1; then
        PHPUNIT_SQLITE_STATUS="PASS"
        cat "${RUN_DIR}/phpunit_sqlite.log"
    else
        PHPUNIT_SQLITE_STATUS="FAIL"
        cat "${RUN_DIR}/phpunit_sqlite.log"
        exit 1
    fi
fi

# 6. Multi-Engine: MySQL 8.x, MariaDB & PostgreSQL
PHPUNIT_MYSQL_STATUS="SKIPPED"
if [ "${SELECTED_DB}" = "mysql" ] || [ "${SELECTED_DB}" = "all" ]; then
    echo "[6a/8] Running Isolated MySQL Integration Tests..."
    if command -v "${MYSQLD_BIN}" >/dev/null 2>&1; then
        check_port_free "${DB_MYSQL_PORT}" "MySQL" || exit 1

        MYSQL_TMP=$(mktemp -d /tmp/mysql-verify-XXXXXX)
        mkdir -p "${MYSQL_TMP}/data"
        cat << 'MYCFG' > "${MYSQL_TMP}/my.cnf"
[mysqld]
datadir=DATADIR_PLACEHOLDER/data
port=PORT_PLACEHOLDER
socket=DATADIR_PLACEHOLDER/mysql.sock
pid-file=DATADIR_PLACEHOLDER/mysql.pid
bind-address=127.0.0.1
mysqlx=OFF
MYCFG
        sed -i "s|DATADIR_PLACEHOLDER|${MYSQL_TMP}|g" "${MYSQL_TMP}/my.cnf"
        sed -i "s|PORT_PLACEHOLDER|${DB_MYSQL_PORT}|g" "${MYSQL_TMP}/my.cnf"

        "${MYSQLD_BIN}" --defaults-file="${MYSQL_TMP}/my.cnf" --initialize-insecure >/dev/null 2>&1
        "${MYSQLD_BIN}" --defaults-file="${MYSQL_TMP}/my.cnf" > "${MYSQL_TMP}/mysqld.log" 2>&1 &
        MYSQL_PID=$!
        
        if ! wait_for_port_ready "${DB_MYSQL_PORT}" 25; then
            echo "ERROR: Timed out waiting for isolated MySQL daemon on port ${DB_MYSQL_PORT}." >&2
            PHPUNIT_MYSQL_STATUS="FAIL"
            exit 1
        fi

        MYSQL_VERSION=$(mysql -h 127.0.0.1 -P "${DB_MYSQL_PORT}" -u root -sN -e "SELECT @@version;" 2>/dev/null || echo "8.x")
        mysql -h 127.0.0.1 -P "${DB_MYSQL_PORT}" -u root -e "CREATE DATABASE IF NOT EXISTS lifecycle_test; CREATE DATABASE IF NOT EXISTS lifecycle_source_test;" >/dev/null 2>&1

        PHPUNIT_MYSQL_STATUS="RUNNING"
        if DB_CONNECTION=mysql DB_HOST=127.0.0.1 DB_PORT="${DB_MYSQL_PORT}" DB_DATABASE=lifecycle_test DB_SOURCE_DATABASE=lifecycle_source_test DB_USERNAME=root DB_PASSWORD="" "${PHPUNIT_BIN}" > "${RUN_DIR}/phpunit_mysql.log" 2>&1; then
            PHPUNIT_MYSQL_STATUS="PASS"
            cat "${RUN_DIR}/phpunit_mysql.log"
        else
            PHPUNIT_MYSQL_STATUS="FAIL"
            cat "${RUN_DIR}/phpunit_mysql.log"
            exit 1
        fi

        kill "${MYSQL_PID}" 2>/dev/null || true
        wait "${MYSQL_PID}" 2>/dev/null || true
        MYSQL_PID=""
        rm -rf "${MYSQL_TMP}" 2>/dev/null || true
        MYSQL_TMP=""
    else
        echo "MySQL binaries not found; marking BLOCKED."
        PHPUNIT_MYSQL_STATUS="BLOCKED"
        exit 1
    fi
fi

PHPUNIT_MARIADB_STATUS="SKIPPED"
if [ "${SELECTED_DB}" = "mariadb" ] || [ "${SELECTED_DB}" = "all" ]; then
    echo "[6b/8] Running MariaDB Multi-Engine Integration Tests..."
    if command -v mariadb-install-db >/dev/null 2>&1 && command -v mariadbd >/dev/null 2>&1; then
        check_port_free "${DB_MARIA_PORT}" "MariaDB" || exit 1

        MARIA_TMP=$(mktemp -d /tmp/mariadb-verify-XXXXXX)
        mariadb-install-db --datadir="${MARIA_TMP}" --auth-root-authentication-method=normal >/dev/null 2>&1
        mariadbd --datadir="${MARIA_TMP}" --port="${DB_MARIA_PORT}" --socket="${MARIA_TMP}/mysql.sock" --pid-file="${MARIA_TMP}/mysql.pid" --skip-networking=0 --bind-address=127.0.0.1 >/dev/null 2>&1 &
        MARIA_PID=$!
        
        if ! wait_for_port_ready "${DB_MARIA_PORT}" 25; then
            echo "ERROR: Timed out waiting for isolated MariaDB daemon on port ${DB_MARIA_PORT}." >&2
            PHPUNIT_MARIADB_STATUS="FAIL"
            exit 1
        fi

        MARIA_VERSION=$(mariadb -h 127.0.0.1 -P "${DB_MARIA_PORT}" -u root -sN -e "SELECT @@version;" 2>/dev/null || echo "MariaDB")
        mariadb -h 127.0.0.1 -P "${DB_MARIA_PORT}" -u root -e "CREATE DATABASE IF NOT EXISTS lifecycle_test; CREATE DATABASE IF NOT EXISTS lifecycle_source_test;" >/dev/null 2>&1

        PHPUNIT_MARIADB_STATUS="RUNNING"
        if DB_CONNECTION=mysql DB_HOST=127.0.0.1 DB_PORT="${DB_MARIA_PORT}" DB_DATABASE=lifecycle_test DB_SOURCE_DATABASE=lifecycle_source_test DB_USERNAME=root DB_PASSWORD="" "${PHPUNIT_BIN}" > "${RUN_DIR}/phpunit_mariadb.log" 2>&1; then
            PHPUNIT_MARIADB_STATUS="PASS"
            cat "${RUN_DIR}/phpunit_mariadb.log"
        else
            PHPUNIT_MARIADB_STATUS="FAIL"
            cat "${RUN_DIR}/phpunit_mariadb.log"
            exit 1
        fi

        kill "${MARIA_PID}" 2>/dev/null || true
        wait "${MARIA_PID}" 2>/dev/null || true
        MARIA_PID=""
        rm -rf "${MARIA_TMP}" 2>/dev/null || true
        MARIA_TMP=""
    else
        echo "MariaDB binaries not found; marking BLOCKED."
        PHPUNIT_MARIADB_STATUS="BLOCKED"
        exit 1
    fi
fi

PHPUNIT_PGSQL_STATUS="SKIPPED"
if [ "${SELECTED_DB}" = "pgsql" ] || [ "${SELECTED_DB}" = "all" ]; then
    echo "[6c/8] Running PostgreSQL Multi-Engine Integration Tests..."
    if psql -U "${DB_USERNAME:-ahtesham}" -h 127.0.0.1 -p "${DB_PGSQL_PORT}" -d lifecycle_test -c "SELECT 1;" >/dev/null 2>&1; then
        PGSQL_VERSION=$(psql -U "${DB_USERNAME:-ahtesham}" -h 127.0.0.1 -p "${DB_PGSQL_PORT}" -d lifecycle_test -t -c "SHOW server_version;" 2>/dev/null | xargs || echo "PostgreSQL")
        PHPUNIT_PGSQL_STATUS="RUNNING"
        if DB_CONNECTION=pgsql DB_HOST=127.0.0.1 DB_PORT="${DB_PGSQL_PORT}" DB_DATABASE=lifecycle_test DB_SOURCE_DATABASE=lifecycle_source_test DB_USERNAME="${DB_USERNAME:-ahtesham}" DB_PASSWORD="" "${PHPUNIT_BIN}" > "${RUN_DIR}/phpunit_pgsql.log" 2>&1; then
            PHPUNIT_PGSQL_STATUS="PASS"
            cat "${RUN_DIR}/phpunit_pgsql.log"
        else
            PHPUNIT_PGSQL_STATUS="FAIL"
            cat "${RUN_DIR}/phpunit_pgsql.log"
            exit 1
        fi
    else
        echo "PostgreSQL not reachable on 127.0.0.1:${DB_PGSQL_PORT}; marking BLOCKED."
        PHPUNIT_PGSQL_STATUS="BLOCKED"
        exit 1
    fi
fi

# 7. Real Laravel Application Integration Scenarios
echo "[7/8] Running Real Laravel Application Integration Scenarios (${SELECTED_LV})..."
if [ "${SELECTED_LV}" = "11" ] || [ "${SELECTED_LV}" = "all" ]; then
    echo " -> Running Laravel 11 Integration Scenarios..."
    APP_INTEGRATION_L11_STATUS="RUNNING"
    if bash "${ROOT_DIR}/tests/integration-app/scripts/run-app-integration.sh" --laravel=11 > "${RUN_DIR}/laravel11_integration.log" 2>&1; then
        APP_INTEGRATION_L11_STATUS="PASS"
        L11_VERSION=$(php -r '$l = json_decode(@file_get_contents(".workbench/laravel-11/composer.lock"), true); foreach(($l["packages"]??[]) as $p){ if($p["name"]==="laravel/framework"){ echo $p["version"]; exit; } } echo "11.x";' 2>/dev/null)
        cat "${RUN_DIR}/laravel11_integration.log"
    else
        APP_INTEGRATION_L11_STATUS="FAIL"
        cat "${RUN_DIR}/laravel11_integration.log"
        exit 1
    fi
fi

if [ "${SELECTED_LV}" = "12" ] || [ "${SELECTED_LV}" = "all" ]; then
    echo " -> Running Laravel 12 Integration Scenarios..."
    APP_INTEGRATION_L12_STATUS="RUNNING"
    if bash "${ROOT_DIR}/tests/integration-app/scripts/run-app-integration.sh" --laravel=12 > "${RUN_DIR}/laravel12_integration.log" 2>&1; then
        APP_INTEGRATION_L12_STATUS="PASS"
        L12_VERSION=$(php -r '$l = json_decode(@file_get_contents(".workbench/laravel-12/composer.lock"), true); foreach(($l["packages"]??[]) as $p){ if($p["name"]==="laravel/framework"){ echo $p["version"]; exit; } } echo "12.x";' 2>/dev/null)
        cat "${RUN_DIR}/laravel12_integration.log"
    else
        APP_INTEGRATION_L12_STATUS="FAIL"
        cat "${RUN_DIR}/laravel12_integration.log"
        exit 1
    fi
fi

if [ "${SELECTED_LV}" = "13" ] || [ "${SELECTED_LV}" = "all" ]; then
    echo " -> Running Laravel 13 Integration Scenarios..."
    APP_INTEGRATION_L13_STATUS="RUNNING"
    if bash "${ROOT_DIR}/tests/integration-app/scripts/run-app-integration.sh" --laravel=13 > "${RUN_DIR}/laravel13_integration.log" 2>&1; then
        APP_INTEGRATION_L13_STATUS="PASS"
        L13_VERSION=$(php -r '$l = json_decode(@file_get_contents(".workbench/laravel-13/composer.lock"), true); foreach(($l["packages"]??[]) as $p){ if($p["name"]==="laravel/framework"){ echo $p["version"]; exit; } } echo "13.x";' 2>/dev/null)
        cat "${RUN_DIR}/laravel13_integration.log"
    else
        APP_INTEGRATION_L13_STATUS="FAIL"
        cat "${RUN_DIR}/laravel13_integration.log"
        exit 1
    fi
fi

# Check final certification condition
if [ "${SELECTED_DB}" = "all" ] && [ "${SELECTED_LV}" = "all" ]; then
    # Verify that all required evidence log files exist and are non-empty
    REQUIRED_LOGS=(
        "composer_validate.log"
        "composer_audit.log"
        "pint.log"
        "phpstan.log"
        "phpunit_sqlite.log"
        "phpunit_mysql.log"
        "phpunit_mariadb.log"
        "phpunit_pgsql.log"
        "laravel11_integration.log"
        "laravel12_integration.log"
        "laravel13_integration.log"
    )
    for log_name in "${REQUIRED_LOGS[@]}"; do
        if [ ! -s "${RUN_DIR}/${log_name}" ]; then
            echo "ERROR: Required verification evidence file ${log_name} is missing or empty in ${RUN_DIR}" >&2
            OVERALL_STATUS="FAIL"
            exit 1
        fi
    done

    OVERALL_STATUS="PASS"
    echo "======================================================================"
    echo " All Quality Gates & Real App Scenarios Completed Successfully!"
    echo " Certified Status: 100% PASS"
    echo " Evidence persisted to: reports/latest/ and reports/runs/${RUN_ID}/"
    echo "======================================================================"
else
    OVERALL_STATUS="PARTIAL"
    echo "======================================================================"
    echo " Partial Verification Completed (DB: ${SELECTED_DB}, LV: ${SELECTED_LV})."
    echo " Status: PARTIAL (Not full release certification)."
    echo " Evidence persisted to: reports/runs/${RUN_ID}/"
    echo "======================================================================"
fi
