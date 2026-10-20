<?php

declare(strict_types=1);

namespace Clcbws\DatabaseLifecycleSuite\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Schema;
use Clcbws\DatabaseLifecycleSuite\SchemaSnapshotManager;
use Illuminate\Console\ConfirmableTrait;

/**
 * Class SnapshotRestoreCommand
 * 
 * Artisan command to restore a database schema state from a JSON snapshot.
 * Protected by triple-consent confirmation for maximum safety.
 */
class SnapshotRestoreCommand extends Command
{
    use ConfirmableTrait;

    /**
     * @var string
     */
    protected $signature = 'db:snapshot-restore 
                            {name : The snapshot name to restore} 
                            {--database= : The database connection to use}
                            {--force : Force restore without safety prompts}
                            {--recreate : Drop existing tables before restoring}';

    /**
     * @var string
     */
    protected $description = 'RESTORE a database schema from a snapshot (DANGEROUS)';

    /**
     * Execute the console command.
     *
     * @param SchemaSnapshotManager $manager
     * @return int
     */
    public function handle(SchemaSnapshotManager $manager): int
    {
        $name = $this->argument('name');
        $connection = $this->option('database');
        $path = \base_path('database/snapshots');
        $recreate = (bool) $this->option('recreate');

        // The snapshot format currently lacks sufficient information to recreate
        // a table faithfully. Never drop existing data using an incomplete snapshot.
        if ($recreate) {
            $this->error('Unsafe restoration blocked: --recreate is not supported until full schema round-trip restoration is implemented.');
            return self::FAILURE;
        }

        $this->warn("!!! DANGER: You are about to restore structural state from snapshot [{$name}] !!!");
        
        if (!$this->confirmAction($name)) {
            $this->info('Restore aborted.');
            return 0;
        }

        try {
            $snapshot = $manager->load((string) $name, $path);
            $tables = $snapshot['tables'] ?? null;
            if (!is_array($tables)) {
                $this->error('Invalid snapshot: missing tables definition.');
                return self::FAILURE;
            }

            // Validate *all* tables before changing any schema.
            foreach ($tables as $tableName => $definition) {
                if (!is_array($definition) || !$manager->canRestoreWithoutLoss($definition)) {
                    $this->error("Cannot safely restore [{$tableName}]: snapshot contains unsupported schema features.");
                    return self::FAILURE;
                }
            }

            foreach ($tables as $tableName => $definition) {
                if (Schema::connection($connection)->hasTable($tableName)) {
                    if ($recreate) {
                        $this->comment(" - Dropping existing table [{$tableName}]...");
                        Schema::connection($connection)->drop($tableName);
                    } else {
                        $this->warn("Table [{$tableName}] already exists. Skipping (use --recreate to drop first).");
                        continue;
                    }
                }

                $this->info(" + Restoring table: {$tableName}");
                if (!$manager->restoreTable((string) $tableName, $definition, $connection)) {
                    $this->error("Failed to restore table [{$tableName}].");
                    return self::FAILURE;
                }
            }

            $this->info("\n✅ Schema restoration complete.");
        } catch (\Exception $e) {
            $this->error("Restore failed: " . $e->getMessage());
            return 1;
        }

        return 0;
    }

    /**
     * Triple-consent confirmation loop.
     */
    protected function confirmAction(string $name): bool
    {
        if ($this->option('force')) {
            return true;
        }

        $this->error("WARNING: This will modify your database schema.");
        
        if (!$this->confirm("Step 1/3: Are you sure you want to restore snapshot [{$name}]?")) {
            return false;
        }

        if (!$this->confirm("Step 2/3: This may cause data loss if tables are dropped or modified. Continue?")) {
            return false;
        }

        $token = substr(bin2hex(random_bytes(4)), 0, 4);
        $input = $this->ask("Step 3/3: To confirm, type the following verification code [{$token}]");

        return $input === $token;
    }
}
