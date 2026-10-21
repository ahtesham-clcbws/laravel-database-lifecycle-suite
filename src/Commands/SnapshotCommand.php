<?php

declare(strict_types=1);

namespace Clcbws\DatabaseLifecycleSuite\Commands;

use Clcbws\DatabaseLifecycleSuite\SchemaSnapshotManager;
use Illuminate\Console\Command;

/**
 * Class SnapshotCommand
 *
 * Artisan command to capture the current database schema into a JSON snapshot.
 */
class SnapshotCommand extends Command
{
    /**
     * @var string
     */
    protected $signature = 'db:snapshot {name? : Optional name for the snapshot} {--database= : The database connection to use}';

    /**
     * @var string
     */
    protected $description = 'Capture the current database schema into a JSON snapshot';

    /**
     * Execute the console command.
     */
    public function handle(SchemaSnapshotManager $manager): int
    {
        $name = (string) ($this->argument('name') ?: 'snapshot_'.date('Y_m_d_His'));
        $connection = $this->option('database');
        $path = \base_path('database/snapshots');

        try {
            $manager->validateSnapshotName($name);
        } catch (\InvalidArgumentException $e) {
            $this->error($e->getMessage());

            return 1;
        }

        $fullPath = "{$path}/{$name}.json";

        if (file_exists($fullPath)) {
            if (! $this->confirm("Snapshot [{$name}] already exists. Overwrite?", false)) {
                $this->info('Snapshot aborted.');

                return 0;
            }
        }

        $this->info('📸 Capturing schema snapshot'.($connection ? " from [{$connection}]" : '').'...');

        try {
            $savedPath = $manager->capture((string) $name, $path, $connection);
            $this->info("✅ Snapshot saved to: {$savedPath}");
        } catch (\Exception $e) {
            $this->error('Failed to capture snapshot: '.$e->getMessage());

            return 1;
        }

        return 0;
    }
}
