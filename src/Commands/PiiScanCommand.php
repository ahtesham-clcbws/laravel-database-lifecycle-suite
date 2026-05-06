<?php

declare(strict_types=1);

namespace Clcbws\DatabaseLifecycleSuite\Commands;

use Illuminate\Console\Command;
use Clcbws\DatabaseLifecycleSuite\PiiScanner;

/**
 * Class PiiScanCommand
 * 
 * Artisan command to scan for potential PII.
 */
class PiiScanCommand extends Command
{
    /**
     * @var string
     */
    protected $signature = 'db:pii-scan {--database= : The database connection to use}';

    /**
     * @var string
     */
    protected $description = 'Scan the database for columns that potentially contain PII (Sensitive Data)';

    /**
     * Execute the console command.
     *
     * @param PiiScanner $scanner
     * @return int
     */
    public function handle(PiiScanner $scanner): int
    {
        $connection = $this->option('database');
        $this->info("🕵️  Scanning for potential PII" . ($connection ? " in [{$connection}]" : "") . "...");

        $findings = $scanner->scan($connection);

        if (empty($findings)) {
            $this->info('✅ No sensitive columns detected based on naming patterns.');
            return 0;
        }

        $this->warn('⚠️  Found ' . count($findings) . ' potential sensitive columns:');

        $this->table(['Table', 'Column', 'Category'], $findings);

        $this->comment("\nTip: Ensure these columns are encrypted or handled according to GDPR/CCPA regulations.");

        return 0;
    }
}
