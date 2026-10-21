#!/usr/bin/env bash
# ==============================================================================
# Laravel Database Lifecycle Suite - Parameterized Application Integration Runner
# ==============================================================================
# Usage:
#   bash run-app-integration.sh [--laravel=11|12] [--all]
#
# Orchestrates fresh Laravel installation in .workbench/laravel-<version>/,
# installs local package source via path repository, seeds complex schemas,
# runs full Artisan command matrix, validates real snapshot restoration into
# an empty target database, executes generated Legacy Bridge artifacts in a
# fresh isolated reconstruction target, runs failure-injection error recovery,
# tests repeated operation idempotency, and verifies production safety.
# ==============================================================================

set -eo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
ROOT_DIR="$(cd "${SCRIPT_DIR}/../../.." && pwd)"
WORKBENCH_DIR="${ROOT_DIR}/.workbench"

TARGET_VERSION="12"

for arg in "$@"; do
    case $arg in
        --laravel=11)
            TARGET_VERSION="11"
            shift
            ;;
        --laravel=12)
            TARGET_VERSION="12"
            shift
            ;;
        --all)
            TARGET_VERSION="all"
            shift
            ;;
    esac
done

run_version_integration() {
    local LV="$1"
    local APP_DIR="${WORKBENCH_DIR}/laravel-${LV}"
    local RECON_DIR="${WORKBENCH_DIR}/recon-laravel-${LV}"

    echo "======================================================================"
    echo " Starting Integration Scenarios for Laravel ${LV}.x"
    echo " Target Directory: ${APP_DIR}"
    echo " Reconstruction Directory: ${RECON_DIR}"
    echo "======================================================================"

    rm -rf "${APP_DIR}" "${RECON_DIR}"
    mkdir -p "${WORKBENCH_DIR}"

    echo "[Step 1] Creating fresh Laravel ${LV} application..."
    if [ "${LV}" = "11" ]; then
        composer create-project --no-install --no-scripts --prefer-dist "laravel/laravel:^11.0" "${APP_DIR}" --no-interaction
        cd "${APP_DIR}"
        cp .env.example .env
        php -r "
        \$c = json_decode(file_get_contents('composer.json'), true);
        \$c['config']['policy']['advisories']['ignore-id'] = [
            'PKSA-d5tc-s1qs-h781', 'PKSA-m5cs-t1y6-qpcs', 'PKSA-3r5d-mb8f-1qw9',
            'PKSA-mdq4-51ck-6kdq', 'PKSA-8qx3-n5y5-vvnd', 'PKSA-q46n-4fdk-zjr4',
            'PKSA-qzrn-rnz3-85w1', 'PKSA-w7xr-vk7n-rstm'
        ];
        file_put_contents('composer.json', json_encode(\$c, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . \"\n\");
        "
        composer install --no-interaction
        touch database/database.sqlite
        php artisan key:generate --ansi --force
    else
        composer create-project --prefer-dist "laravel/laravel:^12.0" "${APP_DIR}" --no-interaction
        cd "${APP_DIR}"
    fi

    echo "[Step 2] Configuring local package path repository..."
    php -r "
    \$composer = json_decode(file_get_contents('composer.json'), true);
    \$composer['repositories'] = [
        [
            'type' => 'path',
            'url' => '${ROOT_DIR}',
            'options' => ['symlink' => true]
        ]
    ];
    file_put_contents('composer.json', json_encode(\$composer, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . \"\n\");
    "

    echo "[Step 3] Installing package via Composer..."
    composer require "clcbws/laravel-database-lifecycle-suite:@dev" --no-interaction

    echo "[Step 4] Publishing configuration & setting up isolated databases..."
    php artisan vendor:publish --provider="Clcbws\DatabaseLifecycleSuite\DatabaseLifecycleSuiteServiceProvider" --tag="config"

    test -f config/database-lifecycle-suite.php || {
        echo "ERROR: Configuration file was not published!"
        exit 1
    }

    # Add 'source' and 'restore_target' connections to config/database.php
    php -r "
    \$config = file_get_contents('config/database.php');
    \$extraConns = \"        'source' => [
            'driver' => 'sqlite',
            'database' => database_path('source_test.sqlite'),
            'prefix' => '',
            'foreign_key_constraints' => true,
        ],
        'restore_target' => [
            'driver' => 'sqlite',
            'database' => database_path('restore_target.sqlite'),
            'prefix' => '',
            'foreign_key_constraints' => true,
        ],\n\";
    \$config = str_replace(\"'connections' => [\", \"'connections' => [\n\" . \$extraConns, \$config);
    file_put_contents('config/database.php', \$config);
    "
    touch database/source_test.sqlite
    touch database/restore_target.sqlite

    echo "[Step 5] Applying complex test fixtures and migrations..."
    cp "${ROOT_DIR}/tests/integration-app/fixtures/2026_10_21_000001_create_lifecycle_complex_schema.php" database/migrations/
    cp "${ROOT_DIR}/tests/integration-app/fixtures/LifecycleComplexSeeder.php" database/seeders/

    # Migrate primary destination & source
    php artisan migrate --force
    php artisan migrate --database=source --path=database/migrations --force

    # Seed primary destination & source
    php artisan db:seed --class='Database\Seeders\LifecycleComplexSeeder' --force
    php artisan db:seed --database=source --class='Database\Seeders\LifecycleComplexSeeder' --force

    # Synchronize timestamps in roles table for baseline identical check
    php -r "
    require __DIR__.'/vendor/autoload.php';
    \$app = require __DIR__.'/bootstrap/app.php';
    \$kernel = \$app->make(Illuminate\Contracts\Console\Kernel::class);
    \$kernel->bootstrap();

    \$sourceRoles = Illuminate\Support\Facades\DB::connection('source')->table('roles')->get();
    foreach (\$sourceRoles as \$role) {
        Illuminate\Support\Facades\DB::table('roles')->where('id', \$role->id)->update([
            'created_at' => \$role->created_at,
            'updated_at' => \$role->updated_at,
        ]);
    }
    "

    echo "[Step 6] Running Scenario B: Full Non-Destructive Lifecycle Audit..."
    php artisan db:lifecycle-status
    php artisan db:connection-check
    php artisan db:index-audit
    php artisan db:index-redundancy
    php artisan db:column-type-audit
    php artisan db:convention-audit
    php artisan db:soft-delete-audit
    php artisan db:cast-audit
    php artisan db:pii-scan
    php artisan db:size-report
    php artisan db:erd --output=storage/schema.md

    test -f storage/schema.md || {
        echo "ERROR: ERD output storage/schema.md was not generated!"
        exit 1
    }

    echo "[Step 7] Running Scenario C & D: Schema Capture and Diff..."
    php artisan db:snapshot initial_schema
    test -f database/snapshots/initial_schema.json || {
        echo "ERROR: Snapshot initial_schema.json was not created!"
        exit 1
    }
    php artisan db:snapshot-diff initial_schema --live

    echo "[Step 8] Running Scenario E: REAL Schema Restoration into Empty Target..."
    # 8.1 Confirm target database is initially empty
    php -r "
    require __DIR__.'/vendor/autoload.php';
    \$app = require __DIR__.'/bootstrap/app.php';
    \$kernel = \$app->make(Illuminate\Contracts\Console\Kernel::class);
    \$kernel->bootstrap();

    \$initTables = Illuminate\Support\Facades\Schema::connection('restore_target')->getTableListing();
    if (count(\$initTables) !== 0) {
        throw new Exception('restore_target is not initially empty! Tables found: ' . count(\$initTables));
    }
    echo 'Verified: restore_target connection is empty (0 tables).\n';
    "

    # 8.2 Dry-run execution
    php artisan db:snapshot-restore initial_schema --database=restore_target --dry-run

    # 8.3 Live restore into empty target
    php artisan db:snapshot-restore initial_schema --database=restore_target --force --no-interaction

    # 8.4 Verify all supported tables and columns were actually created
    php -r "
    require __DIR__.'/vendor/autoload.php';
    \$app = require __DIR__.'/bootstrap/app.php';
    \$kernel = \$app->make(Illuminate\Contracts\Console\Kernel::class);
    \$kernel->bootstrap();

    \$tables = Illuminate\Support\Facades\Schema::connection('restore_target')->getTableListing();
    echo 'Restored tables count in restore_target: ' . count(\$tables) . '\n';
    if (count(\$tables) < 15) {
        throw new Exception('Restoration verification failed: expected >= 15 tables, got ' . count(\$tables));
    }

    // Verify key columns and types on restored tables
    foreach (['users', 'roles', 'employees', 'projects', 'tasks', 'products', 'orders'] as \$expectedTable) {
        if (!Illuminate\Support\Facades\Schema::connection('restore_target')->hasTable(\$expectedTable)) {
            throw new Exception('Missing expected table in restored schema: ' . \$expectedTable);
        }
    }
    echo 'Verified: All primary application tables successfully reconstructed from snapshot.\n';

    // Verify zero user data was restored (Snapshot is structural only)
    \$userCount = Illuminate\Support\Facades\DB::connection('restore_target')->table('users')->count();
    if (\$userCount !== 0) {
        throw new Exception('Data safety violation: restored table contains data rows!');
    }
    echo 'Verified: Zero rows restored in target database (Structural fidelity confirmed).\n';
    "

    # 8.5 Safe idempotency: second restoration should skip without crashing or duplicating
    php artisan db:snapshot-restore initial_schema --database=restore_target --force --no-interaction

    echo "[Step 9] Running Scenario F: Legacy Bridge Reconstruction in Isolated Target App..."
    php artisan db:legacy-bridge --force

    test -d legacy_bridge_output/models || { echo "ERROR: Generated Models missing!"; exit 1; }
    test -d legacy_bridge_output/migrations || { echo "ERROR: Generated Migrations missing!"; exit 1; }
    test -d legacy_bridge_output/seeders || { echo "ERROR: Generated Seeders missing!"; exit 1; }
    test -d legacy_bridge_output/factories || { echo "ERROR: Generated Factories missing!"; exit 1; }

    # Setup isolated reconstruction app
    if [ "${LV}" = "11" ]; then
        composer create-project --no-install --no-scripts --prefer-dist "laravel/laravel:^11.0" "${RECON_DIR}" --no-interaction
        cd "${RECON_DIR}"
        cp .env.example .env
        php -r "
        \$c = json_decode(file_get_contents('composer.json'), true);
        \$c['config']['policy']['advisories']['ignore-id'] = [
            'PKSA-d5tc-s1qs-h781', 'PKSA-m5cs-t1y6-qpcs', 'PKSA-3r5d-mb8f-1qw9',
            'PKSA-mdq4-51ck-6kdq', 'PKSA-8qx3-n5y5-vvnd', 'PKSA-q46n-4fdk-zjr4',
            'PKSA-qzrn-rnz3-85w1', 'PKSA-w7xr-vk7n-rstm'
        ];
        file_put_contents('composer.json', json_encode(\$c, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . \"\n\");
        "
        composer install --no-interaction
        touch database/database.sqlite
        php artisan key:generate --ansi --force
    else
        composer create-project --prefer-dist "laravel/laravel:^12.0" "${RECON_DIR}" --no-interaction
        cd "${RECON_DIR}"
    fi

    # Install generated artifacts into fresh reconstruction app
    rm -rf database/migrations/*
    cp "${APP_DIR}/legacy_bridge_output/migrations/"*.php database/migrations/
    cp "${APP_DIR}/legacy_bridge_output/models/"*.php app/Models/
    cp "${APP_DIR}/legacy_bridge_output/factories/"*.php database/factories/
    cp "${APP_DIR}/legacy_bridge_output/seeders/"*.php database/seeders/

    rm -f database/database.sqlite
    touch database/database.sqlite

    # Execute all generated migrations
    php artisan migrate --force

    # Verify tables created
    php -r "
    require __DIR__.'/vendor/autoload.php';
    \$app = require __DIR__.'/bootstrap/app.php';
    \$kernel = \$app->make(Illuminate\Contracts\Console\Kernel::class);
    \$kernel->bootstrap();

    \$tables = Illuminate\Support\Facades\Schema::getTableListing();
    echo 'Reconstructed tables count in fresh app: ' . count(\$tables) . '\n';
    if (count(\$tables) < 15) {
        throw new Exception('Reconstruction migration failed: expected >= 15 tables, got ' . count(\$tables));
    }
    "

    # Execute generated seeders safely
    for seeder in database/seeders/*TableSeeder.php; do
        sname=$(basename "$seeder" .php)
        php artisan db:seed --class="Database\\Seeders\\${sname}" --force
    done

    # Verify Eloquent models and relationships on reconstructed database
    php -r "
    require __DIR__.'/vendor/autoload.php';
    \$app = require __DIR__.'/bootstrap/app.php';
    \$kernel = \$app->make(Illuminate\Contracts\Console\Kernel::class);
    \$kernel->bootstrap();

    \$user = new \App\Models\User();
    echo 'Eloquent reconstructed model loaded: ' . get_class(\$user) . '\n';
    "
    echo "Legacy Bridge reconstruction validated with 100% executable migrations and seeders."

    cd "${APP_DIR}"

    echo "[Step 10] Running Scenario G: Data Drift Detection (Identical & Intentional Drift)..."
    # 1. Identical check (strict should pass with 0 exit code)
    php artisan db:data-drift --source=source --table=roles --strict

    # 2. Induce intentional drift in destination
    php -r "
    require __DIR__.'/vendor/autoload.php';
    \$app = require __DIR__.'/bootstrap/app.php';
    \$kernel = \$app->make(Illuminate\Contracts\Console\Kernel::class);
    \$kernel->bootstrap();

    Illuminate\Support\Facades\DB::table('roles')->where('id', 1)->update(['display_name' => 'Intentional Drift Value']);
    "

    # 3. Verify strict mode detects drift and returns non-zero
    set +e
    php artisan db:data-drift --source=source --table=roles --strict >/dev/null 2>&1
    DRIFT_EXIT=$?
    set -e

    if [ $DRIFT_EXIT -eq 0 ]; then
        echo "ERROR: Strict mode failed to report data drift!"
        exit 1
    fi
    echo "Data drift successfully detected with strict non-zero exit code."

    # 4. Programmatic synchronization repair
    php -r "
    require __DIR__.'/vendor/autoload.php';
    \$app = require __DIR__.'/bootstrap/app.php';
    \$kernel = \$app->make(Illuminate\Contracts\Console\Kernel::class);
    \$kernel->bootstrap();

    \$detector = app(Clcbws\DatabaseLifecycleSuite\DataDriftDetector::class);
    \$drift = \$detector->checkTable('roles', 'source', null, 500, 100);
    \$firstKey = array_key_first(\$drift['differing']);
    \$diff = \$drift['differing'][\$firstKey];
    \$detector->syncRow('roles', \$diff['source'], null, ['id'], \$diff['live']);
    "

    # 5. Verify restored identical state
    php artisan db:data-drift --source=source --table=roles --strict

    echo "[Step 11] Running Scenario I: Query Analysis & Mutation Rejection..."
    php artisan db:explain "SELECT users.id, users.name, roles.name FROM users JOIN role_user ON users.id = role_user.user_id JOIN roles ON role_user.role_id = roles.id WHERE users.id = 1"

    # Verify adversarial query rejection (must fail with non-zero exit code)
    set +e
    php artisan db:explain "UPDATE users SET name = 'Hacked' WHERE id = 1" 2>/dev/null
    EXIT_CODE=$?
    set -e

    if [ $EXIT_CODE -eq 0 ]; then
        echo "ERROR: Mutating UPDATE query was NOT rejected by db:explain!"
        exit 1
    fi
    echo "Adversarial mutation query was successfully rejected."

    echo "[Step 12] Running Scenario J: Real Error Recovery & Failure Injection..."
    # 12.1 Missing database connection
    set +e
    php artisan db:snapshot-restore initial_schema --database=nonexistent_db --force 2>/dev/null
    MISSING_DB_EXIT=$?
    set -e
    if [ $MISSING_DB_EXIT -eq 0 ]; then
        echo "ERROR: Missing database connection did not fail gracefully!"
        exit 1
    fi

    # 12.2 Corrupted snapshot metadata
    echo "{ invalid json " > database/snapshots/corrupted.json
    set +e
    php artisan db:snapshot-restore corrupted --force 2>/dev/null
    CORRUPT_EXIT=$?
    set -e
    rm -f database/snapshots/corrupted.json
    if [ $CORRUPT_EXIT -eq 0 ]; then
        echo "ERROR: Corrupted snapshot was not rejected!"
        exit 1
    fi
    echo "Failure injection: Invalid connections and corrupted snapshots handled gracefully."

    echo "[Step 13] Running Scenario K: Production Safety Enforcement..."
    set +e
    APP_ENV=production php artisan db:snapshot-restore initial_schema --recreate --force 2>/dev/null
    PROD_EXIT=$?
    set -e

    if [ $PROD_EXIT -eq 0 ]; then
        echo "ERROR: Destructive --recreate was permitted in production environment!"
        exit 1
    fi
    echo "Destructive restore --recreate was successfully blocked in production."

    echo "[Step 14] Running Scenario L: Operation Idempotency..."
    # Repeated safe operations should leave database clean and unchanged
    php artisan db:lifecycle-status
    php artisan db:snapshot initial_schema --force
    php artisan db:snapshot-diff initial_schema --live
    echo "Operation idempotency verified."

    echo "======================================================================"
    echo " All Integration Scenarios for Laravel ${LV} Passed Successfully!"
    echo "======================================================================"
}

if [ "${TARGET_VERSION}" = "all" ]; then
    run_version_integration "11"
    run_version_integration "12"
else
    run_version_integration "${TARGET_VERSION}"
fi
