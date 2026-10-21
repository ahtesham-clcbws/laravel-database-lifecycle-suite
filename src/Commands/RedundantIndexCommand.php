<?php

declare(strict_types=1);

namespace Clcbws\DatabaseLifecycleSuite\Commands;

use Clcbws\DatabaseLifecycleSuite\RedundantIndexDetector;
use Illuminate\Console\Command;

/**
 * Class RedundantIndexCommand
 *
 * Artisan command to detect redundant indexes.
 */
class RedundantIndexCommand extends Command
{
    /**
     * @var string
     */
    protected $signature = 'db:index-redundancy 
                            {--database= : The database connection to use}
                            {--strict : Return a non-zero exit code if redundant indexes are found}';

    /**
     * @var string
     */
    protected $description = 'Detect redundant indexes that are covered by longer composite indexes';

    /**
     * Execute the console command.
     */
    public function handle(RedundantIndexDetector $detector): int
    {
        $connection = $this->option('database');
        $this->info('🔍 Searching for redundant indexes'.($connection ? " in [{$connection}]" : '').'...');

        $redundant = $detector->getRedundantIndexes($connection);

        if (empty($redundant)) {
            $this->info('✅ No redundant indexes found.');

            return self::SUCCESS;
        }

        $this->warn('⚠️ Found '.count($redundant).' redundant indexes:');

        $this->table(['Table', 'Redundant Index', 'Covered By', 'Columns'], $redundant);

        $this->comment("\nTip: Redundant indexes increase disk usage and slow down write operations (INSERT/UPDATE/DELETE).");

        if ($this->option('strict')) {
            return self::FAILURE;
        }

        return self::SUCCESS;
    }
}
