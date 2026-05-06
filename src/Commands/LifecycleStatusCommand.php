<?php

declare(strict_types=1);

namespace Clcbws\DatabaseLifecycleSuite\Commands;

use Illuminate\Console\Command;
use Clcbws\DatabaseLifecycleSuite\IndexStandardizer;
use Clcbws\DatabaseLifecycleSuite\IndexHealthAuditor;
use Clcbws\DatabaseLifecycleSuite\DataDriftDetector;
use Clcbws\DatabaseLifecycleSuite\SoftDeleteAuditor;
use Clcbws\DatabaseLifecycleSuite\ColumnTypeAuditor;
use Clcbws\DatabaseLifecycleSuite\TableConventionAuditor;
use Clcbws\DatabaseLifecycleSuite\ConnectionHealthChecker;
use Clcbws\DatabaseLifecycleSuite\CastAuditor;
use Clcbws\LaravelSchemaSentinel\Sentinel;

/**
 * Class LifecycleStatusCommand
 * 
 * Artisan command to run a comprehensive health check across all database 
 * lifecycle aspects and output a summary scorecard.
 */
class LifecycleStatusCommand extends Command
{
    /**
     * @var string
     */
    protected $signature = 'db:lifecycle-status {--database= : The database connection to use}';

    /**
     * @var string
     */
    protected $description = 'Run a comprehensive database lifecycle health check';

    /**
     * Execute the console command.
     */
    public function handle(
        IndexStandardizer $standardizer,
        IndexHealthAuditor $healthAuditor,
        DataDriftDetector $driftDetector,
        SoftDeleteAuditor $softDeleteAuditor,
        ColumnTypeAuditor $typeAuditor,
        TableConventionAuditor $conventionAuditor,
        ConnectionHealthChecker $connectionChecker,
        CastAuditor $castAuditor
    ): int {
        $connection = $this->option('database');
        $this->info('📊 Generating Database Lifecycle Scorecard' . ($connection ? " for [{$connection}]" : "") . "...");
        
        $scores = [];
        $modelPath = \base_path((string) \config('database-lifecycle-suite.model_path', 'app/Models'));

        // 1. Connection Health
        $connStatus = $connectionChecker->check([$connection ?: \config('database.default')]);
        $scores[] = [
            'Aspect' => 'Connection Reachability',
            'Status' => $connStatus[0]['Status'],
            'Recommendation' => $connStatus[0]['Status'] === '✅ Online' ? 'Healthy' : 'Check db credentials',
        ];

        // 2. Audit Migration Sync (Structural Drift)
        if (class_exists(Sentinel::class)) {
            $sentinel = app(Sentinel::class);
            $structuralDrifts = $sentinel->getDrifts();
            $scores[] = [
                'Aspect' => 'Migration Sync (Sentinel)',
                'Status' => empty($structuralDrifts) ? '✅ Perfect' : '❌ ' . count($structuralDrifts) . ' structural drifts',
                'Recommendation' => empty($structuralDrifts) ? 'Code & DB in sync' : 'Run db:sentinel-fix',
            ];
        }

        // 3. Audit Index Naming
        $namingDrifts = $standardizer->getNamingDrifts($connection);
        $scores[] = [
            'Aspect' => 'Index Naming',
            'Status' => empty($namingDrifts) ? '✅ Perfect' : '⚠️ ' . count($namingDrifts) . ' Drifts',
            'Recommendation' => empty($namingDrifts) ? 'Keep it up!' : 'Run db:standardize-indexes',
        ];

        // 4. Audit Index Health
        $missingIndexes = $healthAuditor->getMissingIndexes($connection);
        $scores[] = [
            'Aspect' => 'Performance (Indexes)',
            'Status' => empty($missingIndexes) ? '✅ Perfect' : '❌ ' . count($missingIndexes) . ' Missing',
            'Recommendation' => empty($missingIndexes) ? 'Optimized' : 'Run db:index-audit',
        ];

        // 5. Audit Data Drift
        $tables = \config('database-lifecycle-suite.data_audit_tables', []);
        $totalDrift = 0;
        foreach ($tables as $table) {
            $drift = $driftDetector->checkTable($table, (string) \config('database-lifecycle-suite.source_connection'), $connection);
            $totalDrift += count($drift['missing']) + count($drift['extra']) + count($drift['differing']);
        }
        $scores[] = [
            'Aspect' => 'Data Consistency',
            'Status' => $totalDrift === 0 ? '✅ Synced' : '⚠️ ' . $totalDrift . ' Rows Out of Sync',
            'Recommendation' => $totalDrift === 0 ? 'Consistent' : 'Run db:data-drift --fix',
        ];

        // 6. Audit Cast Consistency
        $castMismatches = $castAuditor->audit($modelPath, $connection);
        $scores[] = [
            'Aspect' => 'Eloquent (Casts)',
            'Status' => empty($castMismatches) ? '✅ Valid' : '⚠️ ' . count($castMismatches) . ' Mismatches',
            'Recommendation' => empty($castMismatches) ? 'Clean casts' : 'Run db:cast-audit',
        ];

        // 7. Audit Soft Deletes
        $softDeleteInconsistencies = $softDeleteAuditor->audit($modelPath, $connection);
        $scores[] = [
            'Aspect' => 'Eloquent (Soft Deletes)',
            'Status' => empty($softDeleteInconsistencies) ? '✅ Perfect' : '⚠️ ' . count($softDeleteInconsistencies) . ' issues',
            'Recommendation' => empty($softDeleteInconsistencies) ? 'Consistent' : 'Run db:soft-delete-audit',
        ];

        // 8. Audit Column Types
        $typeInconsistencies = $typeAuditor->audit($connection);
        $scores[] = [
            'Aspect' => 'Data Integrity (Types)',
            'Status' => empty($typeInconsistencies) ? '✅ Consistent' : '❌ ' . count($typeInconsistencies) . ' mismatched columns',
            'Recommendation' => empty($typeInconsistencies) ? 'No mismatches' : 'Run db:column-type-audit',
        ];

        // 9. Audit Table Conventions
        $namingViolations = $conventionAuditor->audit($connection);
        $scores[] = [
            'Aspect' => 'Convention (Table Names)',
            'Status' => empty($namingViolations) ? '✅ Standard' : '⚠️ ' . count($namingViolations) . ' violations',
            'Recommendation' => empty($namingViolations) ? 'Standard' : 'Run db:convention-audit',
        ];

        $this->table(['Aspect', 'Status', 'Recommendation'], $scores);

        return 0;
    }
}
