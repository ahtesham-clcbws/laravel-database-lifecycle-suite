<?php

declare(strict_types=1);

namespace Clcbws\DatabaseLifecycleSuite\Commands;

use Illuminate\Console\Command;
use Clcbws\DatabaseLifecycleSuite\TableConventionAuditor;

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
    protected $signature = 'db:convention-audit {--database= : The database connection to use}';

    /**
     * @var string
     */
    protected $description = 'Detect database tables that do not follow Laravel naming conventions (snake_case, plural)';

    /**
     * Execute the console command.
     *
     * @param TableConventionAuditor $auditor
     * @return int
     */
    public function handle(TableConventionAuditor $auditor): int
    {
        $connection = $this->option('database');
        $this->info("🔍 Auditing table naming conventions" . ($connection ? " in [{$connection}]" : "") . "...");

        $violations = $auditor->audit($connection);

        if (empty($violations)) {
            $this->info('✅ All tables follow Laravel naming conventions.');
            return 0;
        }

        $this->warn('⚠️ Found ' . count($violations) . ' convention violations:');

        $this->table(['Full Table Name', 'Current', 'Expected'], $violations);

        $this->comment("\nTip: Following Laravel conventions (e.g., 'users' instead of 'User' or 'tbl_user') allows Eloquent to automatically map models without explicit \$table definitions.");

        return 0;
    }
}
