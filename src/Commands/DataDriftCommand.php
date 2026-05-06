<?php

declare(strict_types=1);

namespace Clcbws\DatabaseLifecycleSuite\Commands;

use Illuminate\Console\Command;
use Illuminate\Console\ConfirmableTrait;
use Clcbws\DatabaseLifecycleSuite\DataDriftDetector;

/**
 * Class DataDriftCommand
 * 
 * Artisan command to identify row-level discrepancies between lookup tables 
 * on different database connections. Supports interactive synchronization 
 * with production safety guards.
 */
class DataDriftCommand extends Command
{
    use ConfirmableTrait;

    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'db:data-drift 
                            {--source= : Source connection to compare against} 
                            {--database= : The live database connection to check}
                            {--table= : Specific table to check}
                            {--fix : Interactively fix the detected drifts}
                            {--force : Force the operation to run when in production}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Detect differences in reference data between local and source connections';

    /**
     * Execute the console command.
     *
     * @param DataDriftDetector $detector The underlying data comparison engine.
     * @return int Exit code (0 for success).
     */
    public function handle(DataDriftDetector $detector): int
    {
        $connection = $this->option('database');
        $sourceConnection = $this->option('source') ?: \config('database-lifecycle-suite.source_connection');
        
        $this->info("🔍 Comparing data records" . ($connection ? " in [{$connection}]" : "") . " against [{$sourceConnection}]...");

        $tables = $this->option('table') 
            ? [$this->option('table')] 
            : \config('database-lifecycle-suite.data_audit_tables', []);

        if (empty($tables)) {
            $this->warn('No tables configured for data audit. Update your config/database-lifecycle-suite.php or use --table.');
            return 1;
        }

        foreach ($tables as $table) {
            $this->comment("\nChecking table: {$table}...");
            $drift = $detector->checkTable($table, (string) $sourceConnection, $connection);

            $missingCount = count($drift['missing']);
            $extraCount = count($drift['extra']);
            $differingCount = count($drift['differing']);

            if ($missingCount > 0 || $extraCount > 0 || $differingCount > 0) {
                $this->warn("Drift found in {$table}:");
                $this->line("- Missing in Live: {$missingCount}");
                $this->line("- Extra in Live: {$extraCount}");
                $this->line("- Different values: {$differingCount}");
                $this->comment("Detected Primary Key: {$drift['primary_key']}");

                if ($this->option('fix')) {
                    if (! $this->confirmToProceed()) {
                        continue;
                    }
                    $this->handleFix($table, (string) $sourceConnection, $drift, $detector, $connection);
                }
            } else {
                $this->info("✅ {$table} is in sync.");
            }
        }

        return 0;
    }

    /**
     * Interactively fix detected drifts.
     */
    protected function handleFix(string $table, string $sourceConnection, array $drift, DataDriftDetector $detector, ?string $connection = null): void
    {
        $pk = $drift['primary_key'];

        foreach ($drift['missing'] as $id => $data) {
            if ($this->confirm("Row {$pk} #{$id} is missing in Live. Sync it from Source?", true)) {
                if ($detector->syncRow($table, $sourceConnection, $data, $connection)) {
                    $this->info("Synced Row #{$id}.");
                } else {
                    $this->error("Failed to sync Row #{$id}.");
                }
            }
        }

        foreach ($drift['differing'] as $id => $data) {
            if ($this->confirm("Row {$pk} #{$id} has different values. Overwrite Live with Source data?", true)) {
                if ($detector->syncRow($table, $sourceConnection, $data['source'], $connection)) {
                    $this->info("Updated Row #{$id}.");
                } else {
                    $this->error("Failed to update Row #{$id}.");
                }
            }
        }
    }
}
