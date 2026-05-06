<?php

declare(strict_types=1);

namespace Clcbws\DatabaseLifecycleSuite\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Schema;
use Clcbws\DatabaseLifecycleSuite\LegacyBridge;

/**
 * Class LegacyBridgeCommand
 * 
 * Artisan command to reverse-engineer a database into Laravel artifacts.
 */
class LegacyBridgeCommand extends Command
{
    /**
     * @var string
     */
    protected $signature = 'db:legacy-bridge 
                            {--tables= : Comma-separated list of tables (defaults to all)} 
                            {--database= : The database connection to use}';

    /**
     * @var string
     */
    protected $description = 'Reverse-engineer a legacy database into migrations, models, and seeders';

    /**
     * Execute the console command.
     *
     * @param LegacyBridge $bridge The reverse-engineering engine.
     * @return int Exit code (0 for success).
     */
    public function handle(LegacyBridge $bridge): int
    {
        $connection = $this->option('database');
        $this->info("🌉 Initializing Legacy Bridge" . ($connection ? " for [{$connection}]" : "") . "...");

        // Determine which tables to process.
        $dbName = \Illuminate\Support\Facades\DB::connection($connection)->getDatabaseName();
        $tables = $this->option('tables') 
            ? explode(',', $this->option('tables')) 
            : \collect(Schema::connection($connection)->getTables())
                ->filter(fn($table) => ($table['schema'] ?? null) === $dbName)
                ->pluck('name')
                ->toArray();

        if (empty($tables)) {
            $this->warn('No tables found to process.');
            return 0;
        }

        $outputDir = \base_path('legacy_bridge_output');
        $this->comment("Generating artifacts in: {$outputDir}...");

        $summary = $bridge->reverseEngineer($tables, $outputDir, $connection);

        // Output a clean summary table for the user.
        $this->table(['Table', 'Migration', 'Model', 'Seeder', 'Factory'], array_map(function ($table, $files) {
            return array_merge([$table], array_values($files));
        }, array_keys($summary), $summary));

        $this->info("\nLegacy Bridge completed successfully.");

        return 0;
    }
}
