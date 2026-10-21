<?php

declare(strict_types=1);

namespace Clcbws\DatabaseLifecycleSuite\Commands;

use Clcbws\DatabaseLifecycleSuite\IndexHealthAuditor;
use Illuminate\Console\Command;

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
    protected $signature = 'db:index-audit 
                            {--database= : The database connection to use}
                            {--strict : Return a non-zero exit code if missing foreign key indexes are found}';

    /**
     * @var string
     */
    protected $description = 'Detect missing indexes on foreign key columns';

    /**
     * Execute the console command.
     */
    public function handle(IndexHealthAuditor $auditor): int
    {
        $connection = $this->option('database');
        $this->info('🔍 Auditing foreign key indexes'.($connection ? " in [{$connection}]" : '').'...');

        $missing = $auditor->getMissingIndexes($connection);

        if (empty($missing)) {
            $this->info('✅ All foreign keys are correctly indexed.');

            return self::SUCCESS;
        }

        $this->warn('⚠️ Found '.count($missing).' unindexed columns that should likely have an index:');

        $rows = array_map(function ($item) {
            return [
                $item['table'],
                $item['column'],
                $item['type'],
                $item['details'],
            ];
        }, $missing);

        $this->table(['Table', 'Column', 'Issue Type', 'Details'], $rows);

        $this->comment("\nTip: Indexing foreign keys improves JOIN performance and prevents table locks during deletes.");

        if ($this->option('strict')) {
            return self::FAILURE;
        }

        return self::SUCCESS;
    }
}
