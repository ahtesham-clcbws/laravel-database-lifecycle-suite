<?php

declare(strict_types=1);

namespace Clcbws\DatabaseLifecycleSuite\Commands;

use Illuminate\Console\Command;
use Clcbws\DatabaseLifecycleSuite\CastAuditor;

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
    protected $signature = 'db:cast-audit {--path=app/Models : Path to models} {--database= : The database connection to use}';

    /**
     * @var string
     */
    protected $description = 'Detect inconsistencies between Eloquent Model $casts and actual database column types';

    /**
     * Execute the console command.
     *
     * @param CastAuditor $auditor
     * @return int
     */
    public function handle(CastAuditor $auditor): int
    {
        $path = \base_path($this->option('path'));
        $connection = $this->option('database');

        $this->info("🔍 Auditing Model Cast consistency" . ($connection ? " in [{$connection}]" : "") . "...");

        $mismatches = $auditor->audit($path, $connection);

        if (empty($mismatches)) {
            $this->info('✅ All model casts are consistent with database types.');
            return 0;
        }

        $this->warn('⚠️ Found ' . count($mismatches) . ' cast inconsistencies:');

        $this->table(['Model', 'Column', 'Cast Type', 'DB Type'], $mismatches);

        return 0;
    }
}
