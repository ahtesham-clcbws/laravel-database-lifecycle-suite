<?php

declare(strict_types=1);

namespace Clcbws\DatabaseLifecycleSuite\Commands;

use Illuminate\Console\Command;
use Clcbws\DatabaseLifecycleSuite\RedundantIndexDetector;

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
    protected $signature = 'db:index-redundancy {--database= : The database connection to use}';

    /**
     * @var string
     */
    protected $description = 'Detect redundant indexes that are covered by longer composite indexes';

    /**
     * Execute the console command.
     *
     * @param RedundantIndexDetector $detector
     * @return int
     */
    public function handle(RedundantIndexDetector $detector): int
    {
        $connection = $this->option('database');
        $this->info("🔍 Searching for redundant indexes" . ($connection ? " in [{$connection}]" : "") . "...");

        $redundant = $detector->getRedundantIndexes($connection);

        if (empty($redundant)) {
            $this->info('✅ No redundant indexes found.');
            return 0;
        }

        $this->warn('⚠️ Found ' . count($redundant) . ' redundant indexes:');

        $this->table(['Table', 'Redundant Index', 'Covered By', 'Columns'], $redundant);

        $this->comment("\nTip: Redundant indexes increase disk usage and slow down write operations (INSERT/UPDATE/DELETE).");

        return 0;
    }
}
