<?php

declare(strict_types=1);

namespace Clcbws\DatabaseLifecycleSuite\Commands;

use Clcbws\DatabaseLifecycleSuite\ColumnTypeAuditor;
use Illuminate\Console\Command;

/**
 * Class ColumnTypeAuditCommand
 *
 * Artisan command to identify inconsistent column types across the database.
 */
class ColumnTypeAuditCommand extends Command
{
    /**
     * @var string
     */
    protected $signature = 'db:column-type-audit 
                            {--database= : The database connection to use}
                            {--strict : Return a non-zero exit code if inconsistent column types are detected}';

    /**
     * @var string
     */
    protected $description = 'Detect inconsistent data types for columns with the same name across tables';

    /**
     * Execute the console command.
     */
    public function handle(ColumnTypeAuditor $auditor): int
    {
        $connection = $this->option('database');
        $this->info('🔍 Auditing column type consistency'.($connection ? " in [{$connection}]" : '').'...');

        $inconsistencies = $auditor->audit($connection);

        if (empty($inconsistencies)) {
            $this->info('✅ No column type inconsistencies found.');

            return self::SUCCESS;
        }

        $this->warn('⚠️ Found '.count($inconsistencies).' inconsistent column types:');

        foreach ($inconsistencies as $column => $usages) {
            $this->comment("\nColumn: {$column}");
            $this->table(['Table', 'Type'], $usages);
        }

        $this->comment("\nTip: Inconsistent types (especially for FKs like user_id) can lead to unexpected database errors and JOIN failures.");

        if ($this->option('strict')) {
            return self::FAILURE;
        }

        return self::SUCCESS;
    }
}
