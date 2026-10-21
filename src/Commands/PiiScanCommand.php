<?php

declare(strict_types=1);

namespace Clcbws\DatabaseLifecycleSuite\Commands;

use Clcbws\DatabaseLifecycleSuite\PiiScanner;
use Illuminate\Console\Command;

/**
 * Class PiiScanCommand
 *
 * Artisan command to scan for potential PII using heuristic pattern matching.
 */
class PiiScanCommand extends Command
{
    /**
     * @var string
     */
    protected $signature = 'db:pii-scan 
                            {--database= : The database connection to use}
                            {--strict : Return a non-zero exit code if potential PII columns are found}';

    /**
     * @var string
     */
    protected $description = 'Heuristically scan database columns for potential PII (Sensitive Data)';

    /**
     * Execute the console command.
     */
    public function handle(PiiScanner $scanner): int
    {
        $connection = $this->option('database');
        $this->info('🕵️  Scanning for potential PII (Heuristic Name Matching)'.($connection ? " in [{$connection}]" : '').'...');
        $this->line('   NOTE: Matches are heuristic indicators based on column names, not verified data content.');

        $findings = $scanner->scan($connection);

        if (empty($findings)) {
            $this->info('✅ No sensitive columns detected based on naming patterns.');

            return self::SUCCESS;
        }

        $this->warn('⚠️  Found '.count($findings).' potential sensitive columns:');

        $rows = array_map(function ($f) {
            return [
                $f['table'],
                $f['column'],
                $f['category'],
                $f['confidence'],
            ];
        }, $findings);

        $this->table(['Table', 'Column', 'Category', 'Confidence'], $rows);

        $this->comment("\nTip: Ensure confirmed sensitive columns are encrypted or handled per GDPR/CCPA regulations.");

        if ($this->option('strict')) {
            return self::FAILURE;
        }

        return self::SUCCESS;
    }
}
