<?php

declare(strict_types=1);

namespace Clcbws\DatabaseLifecycleSuite\Commands;

use Clcbws\DatabaseLifecycleSuite\DataDriftDetector;
use Exception;
use Illuminate\Console\Command;
use Illuminate\Console\ConfirmableTrait;

/**
 * Class DataDriftCommand
 *
 * Artisan command to identify row-level discrepancies between reference tables
 * on different database connections. Supports interactive synchronization
 * with production safety guards, bounded memory limits, and --strict CI reporting.
 */
class DataDriftCommand extends Command
{
    use ConfirmableTrait;

    /**
     * @var string
     */
    protected $signature = 'db:data-drift 
                            {--source= : Source connection to compare against} 
                            {--database= : The live database connection to check}
                            {--table= : Specific table to check}
                            {--fix : Interactively fix detected drifts}
                            {--limit=1000 : Maximum number of drift records to evaluate per table}
                            {--strict : Return a non-zero exit code if any drift is detected}
                            {--force : Force the operation to run when in production}';

    /**
     * @var string
     */
    protected $description = 'Detect differences in reference data between local and source connections';

    /**
     * Execute the console command.
     *
     * @return int Exit code
     */
    public function handle(DataDriftDetector $detector): int
    {
        $connection = $this->option('database');
        $sourceConnection = $this->option('source') ?: \config('database-lifecycle-suite.source_connection');
        $limit = (int) $this->option('limit') ?: 1000;
        $strict = (bool) $this->option('strict');

        if (empty($sourceConnection)) {
            $this->error('No source connection specified. Pass --source or configure database-lifecycle-suite.source_connection.');

            return self::FAILURE;
        }

        $this->info('🔍 Comparing data records'.($connection ? " in [{$connection}]" : ' (default)')." against [{$sourceConnection}]...");

        $tables = $this->option('table')
            ? [trim((string) $this->option('table'))]
            : (array) \config('database-lifecycle-suite.data_audit_tables', []);

        if (empty($tables)) {
            $this->warn('No tables configured for data audit. Update your config/database-lifecycle-suite.php or use --table.');

            return self::FAILURE;
        }

        $totalDriftCount = 0;

        foreach ($tables as $table) {
            $this->comment("\nChecking table: [{$table}]...");

            try {
                $drift = $detector->checkTable($table, (string) $sourceConnection, $connection, 500, $limit);
            } catch (Exception $e) {
                $this->error("Failed to check table [{$table}]: ".$e->getMessage());

                return self::FAILURE;
            }

            $missingCount = count($drift['missing']);
            $extraCount = count($drift['extra']);
            $differingCount = count($drift['differing']);
            $tableDrift = $missingCount + $extraCount + $differingCount;
            $totalDriftCount += $tableDrift;

            $pkList = implode(', ', $drift['primary_keys']);

            if ($tableDrift > 0) {
                $this->warn("⚠️  Drift found in [{$table}] (Primary Key: [{$pkList}]):");
                $this->line("   - Missing in Live (exist in Source): {$missingCount}");
                $this->line("   - Extra in Live (missing in Source): {$extraCount}");
                $this->line("   - Differing values: {$differingCount}");

                if (! empty($drift['truncated'])) {
                    $this->warn("   (Differences truncated at configured limit of {$limit} records)");
                }

                if ($this->option('fix')) {
                    if (! $this->confirmToProceed()) {
                        continue;
                    }
                    $this->handleFix($table, (string) $sourceConnection, $drift, $detector, $connection);
                }
            } else {
                $this->info("✅ [{$table}] is fully in sync.");
            }
        }

        if ($strict && $totalDriftCount > 0) {
            $this->error("\n❌ Strict mode failure: {$totalDriftCount} data drift discrepancies detected.");

            return self::FAILURE;
        }

        return self::SUCCESS;
    }

    /**
     * Interactively fix detected drifts with previews.
     */
    protected function handleFix(
        string $table,
        string $sourceConnection,
        array $drift,
        DataDriftDetector $detector,
        ?string $connection = null
    ): void {
        $pks = $drift['primary_keys'];
        $destName = $connection ?: 'default';
        $synced = 0;
        $failed = 0;

        $this->comment("Preparing to synchronize [{$table}] on destination [{$destName}] from source [{$sourceConnection}]...");

        // Sync missing rows
        foreach ($drift['missing'] as $key => $data) {
            if ($this->confirm("Row [{$key}] is missing in destination. Sync row from Source?", true)) {
                if ($detector->syncRow($table, $data, $connection, $pks)) {
                    $this->info(" + Synced row [{$key}]");
                    $synced++;
                } else {
                    $this->error(" ! Failed to sync row [{$key}]");
                    $failed++;
                }
            }
        }

        // Sync differing rows
        foreach ($drift['differing'] as $key => $diffData) {
            $this->line("\nDiscrepancy preview for row [{$key}]:");
            foreach ($diffData['diff'] as $col => $vals) {
                $srcStr = var_export($vals['source'], true);
                $liveStr = var_export($vals['live'], true);
                $this->line("   Column [{$col}]: Source={$srcStr} vs Live={$liveStr}");
            }

            if ($this->confirm("Overwrite destination row [{$key}] with source data?", true)) {
                if ($detector->syncRow($table, $diffData['source'], $connection, $pks)) {
                    $this->info(" + Updated row [{$key}]");
                    $synced++;
                } else {
                    $this->error(" ! Failed to update row [{$key}]");
                    $failed++;
                }
            }
        }

        $this->info("\nSynchronization summary for [{$table}]: {$synced} synced, {$failed} failed.");
    }
}
