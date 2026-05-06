<?php

declare(strict_types=1);

namespace Clcbws\DatabaseLifecycleSuite\Commands;

use Illuminate\Console\Command;
use Clcbws\DatabaseLifecycleSuite\SchemaSnapshotManager;

/**
 * Class SnapshotDiffCommand
 * 
 * Artisan command to compare two database schema snapshots or compare a 
 * snapshot with the live database.
 */
class SnapshotDiffCommand extends Command
{
    /**
     * @var string
     */
    protected $signature = 'db:snapshot-diff 
                            {base : The baseline snapshot name} 
                            {target? : The snapshot to compare against (ignored if --live is used)}
                            {--live : Compare baseline snapshot against the live database}
                            {--database= : The database connection to use for live comparison}';

    /**
     * @var string
     */
    protected $description = 'Compare two schema snapshots or compare a snapshot against the live database';

    /**
     * Execute the console command.
     *
     * @param SchemaSnapshotManager $manager
     * @return int
     */
    public function handle(SchemaSnapshotManager $manager): int
    {
        $baseName = $this->argument('base');
        $targetName = $this->argument('target');
        $isLive = $this->option('live');
        $connection = $this->option('database');
        
        $path = \base_path('database/snapshots');

        if ($isLive) {
            $this->info("🔄 Comparing snapshot [{$baseName}] against LIVE database" . ($connection ? " in [{$connection}]" : "") . "...");
        } else {
            if (!$targetName) {
                $this->error('Target snapshot name is required when not using --live.');
                return 1;
            }
            $this->info("🔄 Comparing snapshots: {$baseName} -> {$targetName}");
        }

        try {
            $base = $manager->load((string) $baseName, $path);
            
            if ($isLive) {
                $target = $this->generateLiveSnapshot($manager, $connection);
            } else {
                $target = $manager->load((string) $targetName, $path);
            }

            $diff = $manager->compare($base, $target);

            if (empty($diff['tables_added']) && empty($diff['tables_removed']) && empty($diff['table_changes'])) {
                $this->info('✅ No differences found. Schemas are identical.');
                return 0;
            }

            if (!empty($diff['tables_added'])) {
                $this->warn("\n[Tables Added]");
                foreach ($diff['tables_added'] as $t) $this->line(" + {$t}");
            }

            if (!empty($diff['tables_removed'])) {
                $this->error("\n[Tables Removed]");
                foreach ($diff['tables_removed'] as $t) $this->line(" - {$t}");
            }

            foreach ($diff['table_changes'] as $table => $changes) {
                $this->comment("\n[Table: {$table}]");
                
                if (isset($changes['columns_added'])) {
                    foreach ($changes['columns_added'] as $c) $this->line("   + Column: {$c}");
                }
                
                if (isset($changes['columns_removed'])) {
                    foreach ($changes['columns_removed'] as $c) $this->line("   - Column: {$c}");
                }
                
                if (isset($changes['column_changes'])) {
                    foreach ($changes['column_changes'] as $col => $colDiffs) {
                        $this->line("   ~ Column [{$col}]:");
                        foreach ($colDiffs as $prop => $vals) {
                            $from = var_export($vals['from'], true);
                            $to = var_export($vals['to'], true);
                            $this->line("     - {$prop} changed from {$from} to {$to}");
                        }
                    }
                }
            }

        } catch (\Exception $e) {
            $this->error($e->getMessage());
            return 1;
        }

        return 0;
    }

    /**
     * Generate a temporary snapshot of the live database.
     */
    protected function generateLiveSnapshot(SchemaSnapshotManager $manager, ?string $connection): array
    {
        $tempName = 'temp_live_diff_' . bin2hex(random_bytes(8));
        $path = \base_path('database/snapshots/temp');
        
        $savedPath = $manager->capture($tempName, $path, $connection);
        $data = json_decode(file_get_contents($savedPath), true);
        
        @unlink($savedPath);
        if (is_dir($path)) {
            @rmdir($path);
        }
        
        return $data;
    }
}
