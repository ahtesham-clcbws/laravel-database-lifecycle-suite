<?php

declare(strict_types=1);

namespace Clcbws\DatabaseLifecycleSuite\Commands;

use Illuminate\Console\Command;
use Clcbws\DatabaseLifecycleSuite\SoftDeleteAuditor;

/**
 * Class SoftDeleteAuditCommand
 * 
 * Artisan command to identify mismatches between Eloquent SoftDeletes trait 
 * and database 'deleted_at' columns.
 */
class SoftDeleteAuditCommand extends Command
{
    /**
     * @var string
     */
    protected $signature = 'db:soft-delete-audit 
                            {--path=app/Models : Path to models directory} 
                            {--database= : The database connection to use}';

    /**
     * @var string
     */
    protected $description = 'Verify consistency between Eloquent SoftDeletes and database columns';

    /**
     * Execute the console command.
     *
     * @param SoftDeleteAuditor $auditor
     * @return int
     */
    public function handle(SoftDeleteAuditor $auditor): int
    {
        $path = \base_path($this->option('path'));
        $connection = $this->option('database');
        
        $this->info("🔍 Auditing Soft Delete consistency" . ($connection ? " in [{$connection}]" : "") . "...");

        $inconsistencies = $auditor->audit($path, $connection);

        if (empty($inconsistencies)) {
            $this->info('✅ All models and tables are consistent regarding Soft Deletes.');
            return 0;
        }

        $this->warn('⚠️ Found ' . count($inconsistencies) . ' inconsistencies:');

        $this->table(['Model', 'Table', 'Issue'], array_map(function ($i) {
            return [$i['model'], $i['table'], $i['issue']];
        }, $inconsistencies));

        return 0;
    }
}
