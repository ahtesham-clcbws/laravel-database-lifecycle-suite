<?php

declare(strict_types=1);

namespace Clcbws\DatabaseLifecycleSuite\Commands;

use Illuminate\Console\Command;
use Clcbws\DatabaseLifecycleSuite\TableSizeReporter;

/**
 * Class TableSizeReportCommand
 * 
 * Artisan command to report physical table sizes and row counts.
 */
class TableSizeReportCommand extends Command
{
    /**
     * @var string
     */
    protected $signature = 'db:size-report {--database= : The database connection to use}';

    /**
     * @var string
     */
    protected $description = 'Report physical disk size and row count for all tables';

    /**
     * Execute the console command.
     *
     * @param TableSizeReporter $reporter
     * @return int
     */
    public function handle(TableSizeReporter $reporter): int
    {
        $connection = $this->option('database');
        $this->info("📊 Generating table size report" . ($connection ? " for [{$connection}]" : "") . "...");

        try {
            $report = $reporter->getReport($connection);

            $this->table(['Table', 'Rows', 'Size (MB)'], $report);
        } catch (\Exception $e) {
            $this->error($e->getMessage());
            return 1;
        }

        return 0;
    }
}
