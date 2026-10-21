<?php

declare(strict_types=1);

namespace Clcbws\DatabaseLifecycleSuite\Commands;

use Clcbws\DatabaseLifecycleSuite\TableConventionAuditor;
use Illuminate\Console\Command;

/**
 * Class TableConventionAuditCommand
 *
 * Artisan command to identify tables that violate Laravel's naming conventions.
 */
class TableConventionAuditCommand extends Command
{
    /**
     * @var string
     */
    protected $signature = 'db:convention-audit 
                            {--database= : The database connection to use}
                            {--strict : Return a non-zero exit code if naming convention violations are found}';

    /**
     * @var string
     */
    protected $description = 'Detect database tables that do not follow Laravel naming conventions (snake_case, plural)';

    /**
     * Execute the console command.
     */
    public function handle(TableConventionAuditor $auditor): int
    {
        $connection = $this->option('database');
        $this->info('🔍 Auditing table naming conventions'.($connection ? " in [{$connection}]" : '').'...');

        $violations = $auditor->audit($connection);

        if (empty($violations)) {
            $this->info('✅ All tables follow Laravel naming conventions.');

            return self::SUCCESS;
        }

        $this->warn('⚠️ Found '.count($violations).' convention violations:');

        $this->table(['Full Table Name', 'Current', 'Expected'], $violations);

        $this->comment("\nTip: Following Laravel conventions (e.g., 'users' instead of 'User' or 'tbl_user') allows Eloquent to automatically map models without explicit \$table definitions.");

        if ($this->option('strict')) {
            return self::FAILURE;
        }

        return self::SUCCESS;
    }
}
