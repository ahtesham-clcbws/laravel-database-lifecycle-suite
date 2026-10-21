<?php

declare(strict_types=1);

namespace Clcbws\DatabaseLifecycleSuite\Commands;

use Clcbws\DatabaseLifecycleSuite\CastAuditor;
use Illuminate\Console\Command;

/**
 * Class CastAuditCommand
 *
 * Artisan command to identify discrepancies between Model casts and DB types.
 */
class CastAuditCommand extends Command
{
    /**
     * @var string
     */
    protected $signature = 'db:cast-audit 
                            {--path=app/Models : Path to models} 
                            {--database= : The database connection to use}
                            {--strict : Return a non-zero exit code if cast mismatches are detected}';

    /**
     * @var string
     */
    protected $description = 'Detect inconsistencies between Eloquent Model $casts and actual database column types';

    /**
     * Execute the console command.
     */
    public function handle(CastAuditor $auditor): int
    {
        $optPath = (string) $this->option('path');
        $path = str_starts_with($optPath, '/') ? $optPath : \base_path($optPath);
        $connection = $this->option('database');

        $this->info('🔍 Auditing Model Cast consistency'.($connection ? " in [{$connection}]" : '').'...');

        $mismatches = $auditor->audit($path, $connection);

        if (empty($mismatches)) {
            $this->info('✅ All model casts are consistent with database types.');

            return self::SUCCESS;
        }

        $this->warn('⚠️ Found '.count($mismatches).' cast inconsistencies:');

        $this->table(['Model', 'Column', 'Cast Type', 'DB Type'], $mismatches);

        if ($this->option('strict')) {
            return self::FAILURE;
        }

        return self::SUCCESS;
    }
}
