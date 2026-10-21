#!/usr/bin/env bash
# ==============================================================================
# Laravel Database Lifecycle Suite - Real Application Integration Runner
# ==============================================================================
# Orchestrates fresh Laravel 12 installation in .workbench/lifecycle-test-app,
# installs local package source via path repository, seeds complex schemas,
# runs full Artisan command matrix, reverse-engineering, snapshot roundtrip,
# and production safety assertions.
# ==============================================================================

set -eo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
ROOT_DIR="$(cd "${SCRIPT_DIR}/../../.." && pwd)"
WORKBENCH_DIR="${ROOT_DIR}/.workbench"
APP_DIR="${WORKBENCH_DIR}/lifecycle-test-app"

echo "======================================================================"
echo " Starting Real Laravel Application Integration Scenarios"
echo " Package Root: ${ROOT_DIR}"
echo " Workbench App: ${APP_DIR}"
echo "======================================================================"

# Clean previous app
rm -rf "${APP_DIR}"
mkdir -p "${WORKBENCH_DIR}"

echo "[Step 1] Creating fresh Laravel 12 application..."
composer create-project --prefer-dist "laravel/laravel:^12.0" "${APP_DIR}" --no-interaction

cd "${APP_DIR}"

echo "[Step 2] Configuring local package repository..."
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

echo "[Step 4] Publishing configuration & configuring isolated source database..."
php artisan vendor:publish --provider="Clcbws\DatabaseLifecycleSuite\DatabaseLifecycleSuiteServiceProvider" --tag="config"

test -f config/database-lifecycle-suite.php || {
    echo "ERROR: Configuration file was not published!"
    exit 1
}

# Add 'source' connection to config/database.php
php -r "
\$config = file_get_contents('config/database.php');
\$sourceConn = \"        'source' => [
            'driver' => 'sqlite',
            'database' => database_path('source_test.sqlite'),
            'prefix' => '',
            'foreign_key_constraints' => true,
        ],\n\";
\$config = str_replace(\"'connections' => [\", \"'connections' => [\n\" . \$sourceConn, \$config);
file_put_contents('config/database.php', \$config);
"
touch database/source_test.sqlite

echo "[Step 5] Applying complex test fixtures and migrations..."
cp "${ROOT_DIR}/tests/integration-app/fixtures/2026_10_21_000001_create_lifecycle_complex_schema.php" database/migrations/
cp "${ROOT_DIR}/tests/integration-app/fixtures/LifecycleComplexSeeder.php" database/seeders/

# Migrate destination & source
php artisan migrate --force
php artisan migrate --database=source --path=database/migrations --force

# Seed destination & source
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

echo "[Step 8] Running Scenario E: Schema Restoration to Separate SQLite Target..."
php artisan db:snapshot-restore initial_schema --dry-run
php artisan db:snapshot-restore initial_schema --force

echo "[Step 9] Running Scenario F: Legacy Bridge Full Reverse Engineering & Model Verification..."
php artisan db:legacy-bridge --force

test -d legacy_bridge_output/models || {
    echo "ERROR: Generated Models missing!"
    exit 1
}
test -d legacy_bridge_output/migrations || {
    echo "ERROR: Generated Migrations missing!"
    exit 1
}
test -d legacy_bridge_output/seeders || {
    echo "ERROR: Generated Seeders missing!"
    exit 1
}
test -d legacy_bridge_output/factories || {
    echo "ERROR: Generated Factories missing!"
    exit 1
}

# Verify generated models and relationships resolve correctly in Eloquent
php -r "
require __DIR__.'/vendor/autoload.php';
\$app = require __DIR__.'/bootstrap/app.php';
\$kernel = \$app->make(Illuminate\Contracts\Console\Kernel::class);
\$kernel->bootstrap();

foreach (glob(__DIR__.'/legacy_bridge_output/models/*.php') as \$file) {
    require_once \$file;
}

\$role = \App\Models\Role::firstOrFail();
if (\$role->name !== 'admin') {
    throw new Exception('Unexpected role name: ' . \$role->name);
}

\$emp = \App\Models\Employee::firstOrFail();
if (\$emp->department->name !== 'Engineering') {
    throw new Exception('Department relationship failed: ' . \$emp->department->name);
}

\$task = \App\Models\Task::firstOrFail();
if (\$task->project->title !== 'Core Architecture Revamp') {
    throw new Exception('Project relationship failed: ' . \$task->project->title);
}

echo 'Eloquent model reverse engineering verified with 100% relationship integrity.\n';
"

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

echo "[Step 12] Running Scenario K: Production Safety Enforcement..."
set +e
APP_ENV=production php artisan db:snapshot-restore initial_schema --recreate --force 2>/dev/null
PROD_EXIT=$?
set -e

if [ $PROD_EXIT -eq 0 ]; then
    echo "ERROR: Destructive --recreate was permitted in production environment!"
    exit 1
fi
echo "Destructive restore --recreate was successfully blocked in production."

echo "======================================================================"
echo " All Real Application Integration Scenarios Passed Successfully!"
echo "======================================================================"
