<?php

declare(strict_types=1);

namespace Clcbws\DatabaseLifecycleSuite\Commands;

use Clcbws\DatabaseLifecycleSuite\IndexStandardizer;
use Illuminate\Console\Command;
use Illuminate\Console\ConfirmableTrait;

/**
 * Class StandardizeIndexesCommand
 *
 * Artisan command to identify and fix index names that do not follow
 * Laravel's naming conventions. Includes production safety guards and collision prevention.
 */
class StandardizeIndexesCommand extends Command
{
    use ConfirmableTrait;

    /**
     * @var string
     */
    protected $signature = 'db:standardize-indexes 
                            {--database= : The database connection to use}
                            {--dry-run : Only show what would be changed without applying}
                            {--force : Force the operation to run when in production}';

    /**
     * @var string
     */
    protected $description = 'Detect and fix database index names that do not follow standard naming conventions';

    /**
     * Execute the console command.
     *
     * @return int Exit code (0 for success).
     */
    public function handle(IndexStandardizer $standardizer): int
    {
        $connection = $this->option('database');
        $this->info('🔍 Scanning for index naming drifts'.($connection ? " in [{$connection}]" : '').'...');

        $drifts = $standardizer->getNamingDrifts($connection);

        if (empty($drifts)) {
            $this->info('✅ All indexes follow the standard naming convention.');

            return self::SUCCESS;
        }

        $tableRows = array_map(function ($drift) {
            $status = ! empty($drift['collision']) ? '⚠️  Collision' : 'Ready';

            return [$drift['table'], $drift['actual'], $drift['expected'], $status];
        }, $drifts);

        $this->table(['Table', 'Current Name', 'Expected Name', 'Status'], $tableRows);

        if ($this->option('dry-run')) {
            $this->warn('DRY RUN: No changes were made.');

            return self::SUCCESS;
        }

        if (! $this->confirmToProceed()) {
            return self::FAILURE;
        }

        $renamed = 0;
        $skipped = 0;
        $failed = 0;

        foreach ($drifts as $drift) {
            if (! empty($drift['collision'])) {
                $this->error("Skipping [{$drift['actual']}]: Expected name [{$drift['expected']}] already exists on table [{$drift['table']}].");
                $skipped++;

                continue;
            }

            $this->comment("Renaming {$drift['actual']} to {$drift['expected']} on {$drift['table']}...");
            if ($standardizer->renameIndex($drift['table'], $drift['actual'], $drift['expected'], $connection)) {
                $this->info(' + Success.');
                $renamed++;
            } else {
                $this->error(" ! Failed to rename index [{$drift['actual']}].");
                $failed++;
            }
        }

        $this->info("\nStandardization complete: {$renamed} renamed, {$skipped} skipped (collisions), {$failed} failed.");

        return $failed > 0 ? self::FAILURE : self::SUCCESS;
    }
}
