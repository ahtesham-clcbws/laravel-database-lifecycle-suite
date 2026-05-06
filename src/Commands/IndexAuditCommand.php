<?php

declare(strict_types=1);

namespace Clcbws\DatabaseLifecycleSuite\Commands;

use Illuminate\Console\Command;
use Clcbws\DatabaseLifecycleSuite\IndexHealthAuditor;

/**
 * Class IndexAuditCommand
 * 
 * Artisan command to identify foreign keys that are missing database indexes.
 */
class IndexAuditCommand extends Command
{
    /**
     * @var string
     */
    protected $signature = 'db:index-audit {--database= : The database connection to use}';

    /**
     * @var string
     */
    protected $description = 'Detect missing indexes on foreign key columns';

    /**
     * Execute the console command.
     *
     * @param IndexHealthAuditor $auditor The underlying audit engine.
     * @return int Exit code (0 for success).
     */
    public function handle(IndexHealthAuditor $auditor): int
    {
        $connection = $this->option('database');
        $this->info("🔍 Auditing foreign key indexes" . ($connection ? " in [{$connection}]" : "") . "...");

        $missing = $auditor->getMissingIndexes($connection);

        if (empty($missing)) {
            $this->info('✅ All foreign keys are correctly indexed.');
            return 0;
        }

        $this->warn('⚠️ Found ' . count($missing) . ' unindexed columns that should likely have an index:');

        $this->table(['Table', 'Column', 'Issue Type', 'Details'], $missing);

        $this->comment("\nTip: Indexing foreign keys improves JOIN performance and prevents table locks during deletes.");

        return 0;
    }
}
