<?php

declare(strict_types=1);

namespace Clcbws\DatabaseLifecycleSuite\Commands;

use Clcbws\DatabaseLifecycleSuite\LegacyBridge;
use Clcbws\DatabaseLifecycleSuite\Traits\FiltersTables;
use Exception;
use Illuminate\Console\Command;

/**
 * Class LegacyBridgeCommand
 *
 * Artisan command to reverse-engineer a database into Laravel artifacts.
 */
class LegacyBridgeCommand extends Command
{
    use FiltersTables;

    /**
     * @var string
     */
    protected $signature = 'db:legacy-bridge 
                            {--tables= : Comma-separated list of tables (defaults to all)} 
                            {--database= : The database connection to use}
                            {--force : Overwrite existing generated artifact files}';

    /**
     * @var string
     */
    protected $description = 'Reverse-engineer a database into migrations, models, seeders, and factories';

    /**
     * Execute the console command.
     *
     * @param  LegacyBridge  $bridge  The reverse-engineering engine.
     * @return int Exit code (0 for success).
     */
    public function handle(LegacyBridge $bridge): int
    {
        $connection = $this->option('database');
        $this->info('🌉 Initializing Legacy Bridge'.($connection ? " for [{$connection}]" : '').'...');

        try {
            $filtered = $this->getFilteredTables($connection);
            $allTableNames = \collect($filtered)->pluck('name')->toArray();

            $tablesOption = $this->option('tables');
            if ($tablesOption) {
                $requested = array_map('trim', explode(',', (string) $tablesOption));
                $tables = array_values(array_intersect($requested, $allTableNames));
            } else {
                $tables = $allTableNames;
            }

            if (empty($tables)) {
                $this->warn('No tables found to process.');

                return self::SUCCESS;
            }

            $outputDir = \base_path('legacy_bridge_output');
            $this->comment("Generating artifacts in: {$outputDir}...");

            $force = (bool) $this->option('force');
            $summary = $bridge->reverseEngineer($tables, $outputDir, $connection, $force);

            // Output a clean summary table for the user
            $this->table(['Table', 'Migration', 'Model', 'Seeder', 'Factory'], array_map(function ($table, $files) {
                return array_merge([$table], array_values($files));
            }, array_keys($summary), $summary));

            $this->info("\nLegacy Bridge completed successfully.");

            return self::SUCCESS;
        } catch (Exception $e) {
            $this->error('Legacy Bridge failed: '.$e->getMessage());

            return self::FAILURE;
        }
    }
}
