<?php

declare(strict_types=1);

namespace Clcbws\DatabaseLifecycleSuite\Commands;

use Clcbws\DatabaseLifecycleSuite\SchemaSnapshotManager;
use Illuminate\Console\Command;
use Illuminate\Console\ConfirmableTrait;
use Illuminate\Support\Facades\Schema;

/**
 * Class SnapshotRestoreCommand
 *
 * Artisan command to restore a database schema from a previously captured snapshot.
 * Enforces production safety, preflight validation, and non-destructive defaults.
 */
class SnapshotRestoreCommand extends Command
{
    use ConfirmableTrait;

    /**
     * @var string
     */
    protected $signature = 'db:snapshot-restore 
                            {name : The snapshot file name to restore} 
                            {--database= : The database connection to use} 
                            {--recreate : Drop and recreate existing tables if safe (DESTRUCTIVE)}
                            {--dry-run : Preview planned actions without modifying the database}
                            {--force : Force the operation to run when in production}';

    /**
     * @var string
     */
    protected $description = 'Safely restore structural database schema from a snapshot';

    /**
     * Execute the console command.
     */
    public function handle(SchemaSnapshotManager $manager): int
    {
        $name = (string) $this->argument('name');
        $connection = $this->option('database');
        $path = \base_path('database/snapshots');
        $recreate = (bool) $this->option('recreate');
        $dryRun = (bool) $this->option('dry-run');

        // Snapshot is structural only
        $this->warn('⚠️  NOTICE: A schema snapshot restores structural table definitions only.');
        $this->line('   It is NOT a data backup. No table rows or user data will be restored.');

        try {
            $manager->validateSnapshotName($name);
        } catch (\InvalidArgumentException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        try {
            $snapshot = $manager->load($name, $path);
        } catch (\Exception $e) {
            $this->error('Failed to load snapshot: '.$e->getMessage());

            return self::FAILURE;
        }

        $tables = $snapshot['tables'] ?? null;
        if (! is_array($tables) || empty($tables)) {
            $this->error('Invalid snapshot: missing or empty tables definition.');

            return self::FAILURE;
        }

        // Preflight validation: Inspect ALL tables before touching the database
        $preflightErrors = [];
        foreach ($tables as $tableName => $definition) {
            if (! is_array($definition)) {
                $preflightErrors[$tableName] = ['Malformed table definition'];

                continue;
            }

            $lossy = $manager->getLossyFeatures($definition);
            if (! empty($lossy)) {
                $preflightErrors[$tableName] = $lossy;
            }
        }

        if (! empty($preflightErrors)) {
            $this->error('Preflight check failed. Snapshot cannot be restored safely without schema loss:');
            foreach ($preflightErrors as $tbl => $errors) {
                $this->line(" - Table [{$tbl}]: ".implode('; ', $errors));
            }

            return self::FAILURE;
        }

        // Dry Run Mode
        if ($dryRun) {
            $this->info("🔍 [DRY RUN] Previewing restoration for snapshot [{$name}]".($connection ? " on [{$connection}]" : '').':');
            $rows = [];
            foreach ($tables as $tableName => $definition) {
                $exists = Schema::connection($connection)->hasTable($tableName);
                $action = $exists
                    ? ($recreate ? 'DROP & RECREATE' : 'SKIP (already exists)')
                    : 'CREATE';

                $colCount = count($definition['columns'] ?? []);
                $idxCount = count($definition['indexes'] ?? []);
                $fkCount = count($definition['foreign_keys'] ?? []);

                $rows[] = [$tableName, $action, $colCount, $idxCount, $fkCount];
            }

            $this->table(['Table', 'Planned Action', 'Columns', 'Indexes', 'Foreign Keys'], $rows);
            $this->info('Dry run completed. No database changes were made.');

            return self::SUCCESS;
        }

        // Production & Destructive Safeguards
        if ($recreate) {
            $this->warn('!!! DESTRUCTIVE ACTION: --recreate will DROP existing tables and all their data permanently !!!');
            if (! $this->confirmToProceed()) {
                $this->info('Restore aborted.');

                return self::FAILURE;
            }
        } elseif (! $this->confirmAction($name)) {
            $this->info('Restore aborted.');

            return self::SUCCESS;
        }

        // Apply Schema Changes
        $createdTables = [];
        try {
            // Step 1: Drop existing tables if recreate is enabled
            if ($recreate) {
                $tableNames = array_reverse(array_keys($tables));
                foreach ($tableNames as $tableName) {
                    if (Schema::connection($connection)->hasTable($tableName)) {
                        $this->comment(" - Dropping existing table [{$tableName}]...");
                        Schema::connection($connection)->drop($tableName);
                    }
                }
            }

            // Step 2: Create all table structures (columns, primary keys, indexes)
            foreach ($tables as $tableName => $definition) {
                $tableName = (string) $tableName;
                if (Schema::connection($connection)->hasTable($tableName)) {
                    $this->warn("Table [{$tableName}] already exists. Skipping (use --recreate to drop first).");

                    continue;
                }

                $this->info(" + Restoring table: {$tableName}");
                if (! $manager->restoreTable($tableName, $definition, $connection)) {
                    $this->error("Failed to restore table [{$tableName}]. Aborting restoration.");

                    return self::FAILURE;
                }
                $createdTables[] = $tableName;
            }

            // Step 3: Apply Foreign Key constraints in a secondary pass
            foreach ($tables as $tableName => $definition) {
                $tableName = (string) $tableName;
                if (in_array($tableName, $createdTables, true)) {
                    if (! $manager->restoreForeignKeys($tableName, $definition, $connection)) {
                        $this->error("Failed to apply foreign keys for table [{$tableName}].");

                        return self::FAILURE;
                    }
                }
            }

            $this->info("\n✅ Schema structural restoration complete.");

            return self::SUCCESS;
        } catch (\Exception $e) {
            $this->error('Restore failed: '.$e->getMessage());

            return self::FAILURE;
        }
    }

    /**
     * Confirm before proceeding with destructive restore.
     */
    protected function confirmAction(string $name): bool
    {
        return $this->confirm("Are you sure you want to restore schema snapshot [{$name}]?", true);
    }
}
