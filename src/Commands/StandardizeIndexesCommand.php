<?php

declare(strict_types=1);

namespace Clcbws\DatabaseLifecycleSuite\Commands;

use Illuminate\Console\Command;
use Illuminate\Console\ConfirmableTrait;
use Clcbws\DatabaseLifecycleSuite\IndexStandardizer;

/**
 * Class StandardizeIndexesCommand
 * 
 * Artisan command to identify and fix index names that do not follow 
 * Laravel's naming conventions. Includes production safety guards.
 */
class StandardizeIndexesCommand extends Command
{
    use ConfirmableTrait;

    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'db:standardize-indexes 
                            {--database= : The database connection to use}
                            {--dry-run : Only show what would be changed without applying}
                            {--force : Force the operation to run when in production}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Detect and fix database index names that do not follow Laravel conventions';

    /**
     * Execute the console command.
     *
     * @param IndexStandardizer $standardizer The engine that calculates naming drifts.
     * @return int Exit code (0 for success).
     */
    public function handle(IndexStandardizer $standardizer): int
    {
        $connection = $this->option('database');
        $this->info("🔍 Scanning for index naming drifts" . ($connection ? " in [{$connection}]" : "") . "...");
        
        $drifts = $standardizer->getNamingDrifts($connection);

        if (empty($drifts)) {
            $this->info('✅ All indexes follow the standard naming convention.');
            return 0;
        }

        $this->table(['Table', 'Current Name', 'Expected Name'], array_map(function ($drift) {
            return [$drift['table'], $drift['actual'], $drift['expected']];
        }, $drifts));

        if ($this->option('dry-run')) {
            $this->warn('DRY RUN: No changes were made.');
            return 0;
        }

        if (! $this->confirmToProceed()) {
            return 1;
        }

        foreach ($drifts as $drift) {
            $this->comment("Renaming {$drift['actual']} to {$drift['expected']} on {$drift['table']}...");
            if ($standardizer->renameIndex($drift['table'], $drift['actual'], $drift['expected'], $connection)) {
                $this->info('Success.');
            } else {
                $this->error('Failed to rename index.');
            }
        }
        
        $this->info('Standardization complete.');

        return 0;
    }
}
