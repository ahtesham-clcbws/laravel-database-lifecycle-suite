<?php

declare(strict_types=1);

namespace Clcbws\DatabaseLifecycleSuite;

use Illuminate\Support\Facades\Schema;
use Clcbws\DatabaseLifecycleSuite\Traits\MapsDatabaseTypes;
use Clcbws\DatabaseLifecycleSuite\Traits\FiltersTables;
use Exception;

/**
 * Class SchemaSnapshotManager
 * 
 * Manages the persistence, loading, and comparison of database schema states.
 */
readonly class SchemaSnapshotManager
{
    use MapsDatabaseTypes, FiltersTables;

    /**
     * Capture the current database schema into a JSON file.
     */
    public function capture(string $name, string $path, ?string $connection = null): string
    {
        if (!is_dir($path)) {
            if (!@mkdir($path, 0755, true) && !is_dir($path)) {
                throw new Exception("Unable to create directory: {$path}. Check permissions.");
            }
        }

        if (!is_writable($path)) {
            throw new Exception("Directory is not writable: {$path}");
        }

        $schema = [
            'timestamp' => now()->toIso8601String(),
            'tables' => [],
        ];

        $tables = $this->getFilteredTables($connection);

        foreach ($tables as $table) {
            $tableName = $table['name'];
            $schema['tables'][$tableName] = [
                'columns' => Schema::connection($connection)->getColumns($tableName),
                'indexes' => Schema::connection($connection)->getIndexes($tableName),
                'foreign_keys' => Schema::connection($connection)->getForeignKeys($tableName),
            ];
        }

        $fullPath = "{$path}/{$name}.json";
        if (file_put_contents($fullPath, json_encode($schema, JSON_PRETTY_PRINT)) === false) {
            throw new Exception("Failed to write snapshot to: {$fullPath}");
        }

        return $fullPath;
    }

    /**
     * Load a saved schema snapshot from disk.
     */
    public function load(string $name, string $path): array
    {
        $fullPath = "{$path}/{$name}.json";

        if (!file_exists($fullPath)) {
            throw new Exception("Snapshot file not found: {$fullPath}");
        }

        return json_decode((string) file_get_contents($fullPath), true);
    }

    /**
     * Compare two schema snapshots and return the differences.
     */
    public function compare(array $base, array $new): array
    {
        $diff = [
            'tables_added' => [],
            'tables_removed' => [],
            'table_changes' => [],
        ];

        $baseTables = array_keys($base['tables']);
        $newTables = array_keys($new['tables']);

        $diff['tables_added'] = array_diff($newTables, $baseTables);
        $diff['tables_removed'] = array_diff($baseTables, $newTables);

        $commonTables = array_intersect($baseTables, $newTables);

        foreach ($commonTables as $table) {
            $tableChanges = $this->compareTable($base['tables'][$table], $new['tables'][$table]);
            if (!empty($tableChanges)) {
                $diff['table_changes'][$table] = $tableChanges;
            }
        }

        return $diff;
    }

    protected function compareTable(array $base, array $new): array
    {
        $changes = [];

        // Compare Columns
        $baseCols = \collect($base['columns'])->keyBy('name');
        $newCols = \collect($new['columns'])->keyBy('name');

        $added = array_diff($newCols->keys()->toArray(), $baseCols->keys()->toArray());
        $removed = array_diff($baseCols->keys()->toArray(), $newCols->keys()->toArray());

        if (!empty($added)) $changes['columns_added'] = $added;
        if (!empty($removed)) $changes['columns_removed'] = $removed;

        foreach (array_intersect($baseCols->keys()->toArray(), $newCols->keys()->toArray()) as $colName) {
            $baseCol = $baseCols[$colName];
            $newCol = $newCols[$colName];
            
            $colChanges = [];

            if ($baseCol['type_name'] !== $newCol['type_name']) {
                $colChanges['type'] = ['from' => $baseCol['type_name'], 'to' => $newCol['type_name']];
            }

            if ($baseCol['nullable'] !== $newCol['nullable']) {
                $colChanges['nullable'] = ['from' => $baseCol['nullable'], 'to' => $newCol['nullable']];
            }

            if ($baseCol['default'] !== $newCol['default']) {
                $colChanges['default'] = ['from' => $baseCol['default'], 'to' => $newCol['default']];
            }

            if (!empty($colChanges)) {
                $changes['column_changes'][$colName] = $colChanges;
            }
        }

        return $changes;
    }

    /**
     * Restore a table from a snapshot definition.
     */
    public function restoreTable(string $tableName, array $definition, ?string $connection = null): bool
    {
        try {
            Schema::connection($connection)->create($tableName, function ($table) use ($definition) {
                foreach ($definition['columns'] as $column) {
                    $type = $column['type_name'];
                    $table->{$this->mapType($type)}($column['name'])->nullable($column['nullable']);
                }
            });
            return true;
        } catch (Exception $e) {
            return false;
        }
    }
}
