<?php

declare(strict_types=1);

namespace Clcbws\DatabaseLifecycleSuite\Commands;

use Clcbws\DatabaseLifecycleSuite\CastAuditor;
use Clcbws\DatabaseLifecycleSuite\ColumnTypeAuditor;
use Clcbws\DatabaseLifecycleSuite\ConnectionHealthChecker;
use Clcbws\DatabaseLifecycleSuite\DataDriftDetector;
use Clcbws\DatabaseLifecycleSuite\IndexHealthAuditor;
use Clcbws\DatabaseLifecycleSuite\IndexStandardizer;
use Clcbws\DatabaseLifecycleSuite\SoftDeleteAuditor;
use Clcbws\DatabaseLifecycleSuite\TableConventionAuditor;
use Clcbws\LaravelSchemaSentinel\Sentinel;
use Illuminate\Console\Command;
use Throwable;

/**
 * Class LifecycleStatusCommand
 *
 * Artisan command to execute an end-to-end database lifecycle audit, calculate
 * an accurate, deterministic health scorecard, and output CLI or JSON reports.
 */
class LifecycleStatusCommand extends Command
{
    /**
     * @var string
     */
    protected $signature = 'db:lifecycle-status 
                            {--database= : The database connection to use}
                            {--format=table : Output format (table or json)}
                            {--strict : Return non-zero exit code if any check fails}';

    /**
     * @var string
     */
    protected $description = 'Run a comprehensive database lifecycle health check and scorecard';

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
        $format = strtolower((string) ($this->option('format') ?: 'table'));
        $strict = (bool) $this->option('strict');

        if (! in_array($format, ['table', 'json'], true)) {
            $this->error("Invalid format [{$format}]. Supported formats: table, json.");

            return self::FAILURE;
        }

        $isJson = $format === 'json';
        $targetConn = $connection ?: (string) \config('database.default', 'default');
        $modelPath = \base_path((string) \config('database-lifecycle-suite.model_path', 'app/Models'));

        $checks = [];

        // 1. Connection Reachability
        try {
            $connStatus = $connectionChecker->check([$targetConn]);
            $isOnline = ($connStatus[0]['Status'] ?? '') === '✅ Online';
            $checks[] = [
                'aspect' => 'Connection Reachability',
                'status' => $isOnline ? 'PASS' : 'FAIL',
                'points' => null,
                'details' => $connStatus[0]['Latency'] ?? 'N/A',
                'recommendation' => $isOnline ? 'Connection reachable and responsive' : 'Check database credentials and network',
            ];
        } catch (Throwable $e) {
            $checks[] = [
                'aspect' => 'Connection Reachability',
                'status' => 'ERROR',
                'points' => null,
                'details' => $e->getMessage(),
                'recommendation' => 'Inspect database configuration',
            ];
        }

        // 2. Migration Sync (Sentinel Integration)
        if (class_exists(Sentinel::class)) {
            try {
                $sentinel = app(Sentinel::class);
                $structuralDrifts = $sentinel->getDrifts();
                $driftCount = count($structuralDrifts);
                $checks[] = [
                    'aspect' => 'Migration Sync (Sentinel)',
                    'status' => $driftCount === 0 ? 'PASS' : 'FAIL',
                    'points' => null,
                    'details' => $driftCount === 0 ? 'No structural drift' : "{$driftCount} structural drifts detected",
                    'recommendation' => $driftCount === 0 ? 'Code and DB schema match' : 'Run php artisan db:sentinel-fix',
                ];
            } catch (Throwable $e) {
                $checks[] = [
                    'aspect' => 'Migration Sync (Sentinel)',
                    'status' => 'ERROR',
                    'points' => null,
                    'details' => $e->getMessage(),
                    'recommendation' => 'Check Sentinel configuration',
                ];
            }
        } else {
            $checks[] = [
                'aspect' => 'Migration Sync (Sentinel)',
                'status' => 'SKIPPED',
                'points' => null,
                'details' => 'clcbws/laravel-schema-sentinel not installed',
                'recommendation' => 'Install Schema Sentinel for structural migration verification',
            ];
        }

        // 3. Index Naming Standards
        try {
            $namingDrifts = $standardizer->getNamingDrifts($connection);
            $driftCount = count($namingDrifts);
            $checks[] = [
                'aspect' => 'Index Naming Standards',
                'status' => $driftCount === 0 ? 'PASS' : 'WARNING',
                'points' => null,
                'details' => $driftCount === 0 ? 'All indexes conform to standard pattern' : "{$driftCount} non-standard index names",
                'recommendation' => $driftCount === 0 ? 'Optimal' : 'Run php artisan db:standardize-indexes',
            ];
        } catch (Throwable $e) {
            $checks[] = [
                'aspect' => 'Index Naming Standards',
                'status' => 'ERROR',
                'points' => null,
                'details' => $e->getMessage(),
                'recommendation' => 'Check index auditor configuration',
            ];
        }

        // 4. Index Health (Missing Foreign Key Indexes)
        try {
            $missingIndexes = $healthAuditor->getMissingIndexes($connection);
            $formalMissing = \collect($missingIndexes)->where('type', 'Formal Foreign Key')->count();
            $potentialMissing = \collect($missingIndexes)->where('type', 'Potential Foreign Key')->count();

            if ($formalMissing > 0) {
                $status = 'FAIL';
                $details = "{$formalMissing} unindexed foreign keys ({$potentialMissing} heuristic candidates)";
                $rec = 'Run php artisan db:index-audit';
            } elseif ($potentialMissing > 0) {
                $status = 'WARNING';
                $details = "{$potentialMissing} unindexed potential foreign keys (*_id)";
                $rec = 'Review and index foreign key candidates';
            } else {
                $status = 'PASS';
                $details = 'All foreign keys are properly indexed';
                $rec = 'Optimized for JOIN and CASCADE performance';
            }

            $checks[] = [
                'aspect' => 'Foreign Key Index Health',
                'status' => $status,
                'points' => null,
                'details' => $details,
                'recommendation' => $rec,
            ];
        } catch (Throwable $e) {
            $checks[] = [
                'aspect' => 'Foreign Key Index Health',
                'status' => 'ERROR',
                'points' => null,
                'details' => $e->getMessage(),
                'recommendation' => 'Inspect index health checker',
            ];
        }

        // 5. Data Consistency (Data Drift)
        $auditTables = (array) \config('database-lifecycle-suite.data_audit_tables', []);
        $sourceConn = \config('database-lifecycle-suite.source_connection');

        if (empty($auditTables) || empty($sourceConn)) {
            $checks[] = [
                'aspect' => 'Reference Data Consistency',
                'status' => 'NOT CONFIGURED',
                'points' => null,
                'details' => 'No reference tables configured in database-lifecycle-suite.data_audit_tables',
                'recommendation' => 'Configure data_audit_tables to monitor reference data drift',
            ];
        } else {
            try {
                $totalDrift = 0;
                foreach ($auditTables as $tbl) {
                    $drift = $driftDetector->checkTable((string) $tbl, (string) $sourceConn, $connection);
                    $totalDrift += count($drift['missing']) + count($drift['extra']) + count($drift['differing']);
                }

                $checks[] = [
                    'aspect' => 'Reference Data Consistency',
                    'status' => $totalDrift === 0 ? 'PASS' : 'WARNING',
                    'points' => null,
                    'details' => $totalDrift === 0 ? 'All reference tables match source' : "{$totalDrift} discrepancies across audited tables",
                    'recommendation' => $totalDrift === 0 ? 'In sync' : 'Run php artisan db:data-drift --fix',
                ];
            } catch (Throwable $e) {
                $checks[] = [
                    'aspect' => 'Reference Data Consistency',
                    'status' => 'ERROR',
                    'points' => null,
                    'details' => $e->getMessage(),
                    'recommendation' => 'Verify source and destination connectivity',
                ];
            }
        }

        // 6. Eloquent Cast Consistency
        try {
            $castMismatches = $castAuditor->audit($modelPath, $connection);
            $mismatchCount = count($castMismatches);
            $checks[] = [
                'aspect' => 'Eloquent Model Casts',
                'status' => $mismatchCount === 0 ? 'PASS' : 'WARNING',
                'points' => null,
                'details' => $mismatchCount === 0 ? 'All model casts match DB column types' : "{$mismatchCount} cast type mismatches detected",
                'recommendation' => $mismatchCount === 0 ? 'Strict type consistency' : 'Run php artisan db:cast-audit',
            ];
        } catch (Throwable $e) {
            $checks[] = [
                'aspect' => 'Eloquent Model Casts',
                'status' => 'ERROR',
                'points' => null,
                'details' => $e->getMessage(),
                'recommendation' => 'Verify model paths and class definitions',
            ];
        }

        // 7. Eloquent Soft Deletes
        try {
            $softDeleteIssues = $softDeleteAuditor->audit($modelPath, $connection);
            $issueCount = count($softDeleteIssues);
            $checks[] = [
                'aspect' => 'Soft Delete Consistency',
                'status' => $issueCount === 0 ? 'PASS' : 'WARNING',
                'points' => null,
                'details' => $issueCount === 0 ? 'All SoftDeletes models have corresponding DB columns' : "{$issueCount} soft delete inconsistencies",
                'recommendation' => $issueCount === 0 ? 'Consistent' : 'Run php artisan db:soft-delete-audit',
            ];
        } catch (Throwable $e) {
            $checks[] = [
                'aspect' => 'Soft Delete Consistency',
                'status' => 'ERROR',
                'points' => null,
                'details' => $e->getMessage(),
                'recommendation' => 'Check soft delete auditor',
            ];
        }

        // 8. Cross-Table Column Type Uniformity
        try {
            $typeInconsistencies = $typeAuditor->audit($connection);
            $typeIssueCount = count($typeInconsistencies);
            $checks[] = [
                'aspect' => 'Column Type Uniformity',
                'status' => $typeIssueCount === 0 ? 'PASS' : 'FAIL',
                'points' => null,
                'details' => $typeIssueCount === 0 ? 'Consistent data types across shared column names' : "{$typeIssueCount} columns with mismatched types across tables",
                'recommendation' => $typeIssueCount === 0 ? 'Consistent integrity' : 'Run php artisan db:column-type-audit',
            ];
        } catch (Throwable $e) {
            $checks[] = [
                'aspect' => 'Column Type Uniformity',
                'status' => 'ERROR',
                'points' => null,
                'details' => $e->getMessage(),
                'recommendation' => 'Inspect column type auditor',
            ];
        }

        // 9. Table Naming Conventions
        try {
            $namingViolations = $conventionAuditor->audit($connection);
            $violationCount = count($namingViolations);
            $checks[] = [
                'aspect' => 'Table Naming Conventions',
                'status' => $violationCount === 0 ? 'PASS' : 'WARNING',
                'points' => null,
                'details' => $violationCount === 0 ? 'All tables follow snake_case plural convention' : "{$violationCount} tables violate Laravel naming conventions",
                'recommendation' => $violationCount === 0 ? 'Standard naming' : 'Run php artisan db:convention-audit',
            ];
        } catch (Throwable $e) {
            $checks[] = [
                'aspect' => 'Table Naming Conventions',
                'status' => 'ERROR',
                'points' => null,
                'details' => $e->getMessage(),
                'recommendation' => 'Inspect convention auditor',
            ];
        }

        // Score Calculation (PASS = 100, WARNING = 50, FAIL = 0, ERROR = 0)
        // SKIPPED and NOT CONFIGURED are excluded from the denominator.
        $evaluatedCount = 0;
        $totalEarned = 0;
        $hasFailure = false;
        $scoredChecks = [];

        foreach ($checks as $check) {
            $st = $check['status'];
            $points = null;
            if ($st === 'PASS') {
                $points = 100;
                $totalEarned += 100;
                $evaluatedCount++;
            } elseif ($st === 'WARNING') {
                $points = 50;
                $totalEarned += 50;
                $evaluatedCount++;
            } elseif ($st === 'FAIL' || $st === 'ERROR') {
                $points = 0;
                $evaluatedCount++;
                $hasFailure = true;
            }

            $check['points'] = $points;
            $scoredChecks[] = $check;
        }

        $healthScore = $evaluatedCount > 0
            ? (int) round($totalEarned / ($evaluatedCount * 100) * 100)
            : 0;

        // JSON Output Mode
        if ($isJson) {
            $payload = [
                'connection' => $targetConn,
                'health_score' => $healthScore,
                'evaluated_checks' => $evaluatedCount,
                'checks' => $scoredChecks,
            ];
            $this->output->write((string) json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)."\n");

            return ($strict && $hasFailure) ? self::FAILURE : self::SUCCESS;
        }

        // Terminal Table Mode
        $this->info("📊 Database Lifecycle Scorecard for [{$targetConn}]");
        $this->line('─────────────────────────────────────────────────────────────────────────────');

        $rows = [];
        foreach ($scoredChecks as $c) {
            $badge = match ($c['status']) {
                'PASS' => '✅ PASS',
                'WARNING' => '⚠️  WARNING',
                'FAIL' => '❌ FAIL',
                'SKIPPED' => '⏭️  SKIPPED',
                'NOT CONFIGURED' => '⚪ NOT CONFIGURED',
                'ERROR' => '🛑 ERROR',
            };

            $pts = $c['points'] !== null ? "{$c['points']}%" : 'N/A';
            $rows[] = [$c['aspect'], $badge, $pts, $c['details'], $c['recommendation']];
        }

        $this->table(['Aspect', 'Status', 'Score', 'Details', 'Recommendation'], $rows);

        $this->line('─────────────────────────────────────────────────────────────────────────────');
        $this->info("📈 Overall Database Health Score: {$healthScore}% (Evaluated: {$evaluatedCount}/".count($scoredChecks).' checks)');

        if ($strict && $hasFailure) {
            $this->error('Strict mode failure: One or more database lifecycle checks failed.');

            return self::FAILURE;
        }

        return self::SUCCESS;
    }
}
