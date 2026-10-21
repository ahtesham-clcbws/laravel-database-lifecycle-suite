<?php

declare(strict_types=1);

namespace Clcbws\DatabaseLifecycleSuite\Commands;

use Clcbws\DatabaseLifecycleSuite\SoftDeleteAuditor;
use Illuminate\Console\Command;

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
                            {--database= : The database connection to use}
                            {--strict : Return a non-zero exit code if soft delete mismatches are found}';

    /**
     * @var string
     */
    protected $description = 'Verify consistency between Eloquent SoftDeletes and database columns';

    /**
     * Execute the console command.
     */
    public function handle(SoftDeleteAuditor $auditor): int
    {
        $optPath = (string) $this->option('path');
        $path = str_starts_with($optPath, '/') ? $optPath : \base_path($optPath);
        $connection = $this->option('database');

        $this->info('🔍 Auditing Soft Delete consistency'.($connection ? " in [{$connection}]" : '').'...');

        $inconsistencies = $auditor->audit($path, $connection);

        if (empty($inconsistencies)) {
            $this->info('✅ All models and tables are consistent regarding Soft Deletes.');

            return self::SUCCESS;
        }

        $this->warn('⚠️ Found '.count($inconsistencies).' inconsistencies:');

        $this->table(['Model', 'Table', 'Issue'], array_map(function ($i) {
            return [$i['model'], $i['table'], $i['issue']];
        }, $inconsistencies));

        if ($this->option('strict')) {
            return self::FAILURE;
        }

        return self::SUCCESS;
    }
}
