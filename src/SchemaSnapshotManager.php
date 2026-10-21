<?php

declare(strict_types=1);

namespace Clcbws\DatabaseLifecycleSuite;

use Clcbws\DatabaseLifecycleSuite\Traits\FiltersTables;
use Clcbws\DatabaseLifecycleSuite\Traits\MapsDatabaseTypes;
use Exception;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use InvalidArgumentException;

/**
 * Class SchemaSnapshotManager
 *
 * Manages the persistence, loading, comparison, and safe restoration of database schema states.
 */
class SchemaSnapshotManager
{
    use FiltersTables, MapsDatabaseTypes;

    public const SUPPORTED_VERSION = '1.0';

    protected ?string $lastError = null;

    public function getLastError(): ?string
    {
        return $this->lastError;
    }

    /**
     * Validate that a snapshot name is safe and does not contain path traversal characters.
     *
     * @throws InvalidArgumentException
     */
    public function validateSnapshotName(string $name): void
    {
        if (trim($name) === '' || ! preg_match('/^[a-zA-Z0-9_\-]+$/', $name)) {
            throw new InvalidArgumentException("Invalid snapshot name: [{$name}]. Snapshot names may only contain alphanumeric characters, underscores, and dashes.");
        }
    }

    /**
     * Capture the current database schema into a JSON file.
     *
     * @throws Exception
     */
    public function capture(string $name, string $path, ?string $connection = null): string
    {
        $this->validateSnapshotName($name);

        if (! is_dir($path)) {
            if (! @mkdir($path, 0755, true) && ! is_dir($path)) {
                throw new Exception("Unable to create directory: {$path}. Check permissions.");
            }
        }

        if (! is_writable($path)) {
            throw new Exception("Directory is not writable: {$path}");
        }

        $schema = [
            'version' => self::SUPPORTED_VERSION,
            'timestamp' => now()->toIso8601String(),
            'connection' => $connection ?: 'default',
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
        $encoded = json_encode($schema, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        if ($encoded === false || file_put_contents($fullPath, $encoded) === false) {
            throw new Exception("Failed to write snapshot to: {$fullPath}");
        }

        return $fullPath;
    }

    /**
     * Load a saved schema snapshot from disk and validate its contents.
     *
     * @throws InvalidArgumentException|Exception
     */
    public function load(string $name, string $path): array
    {
        $this->validateSnapshotName($name);

        $fullPath = "{$path}/{$name}.json";

        if (! file_exists($fullPath)) {
            throw new Exception("Snapshot file not found: {$fullPath}");
        }

        $raw = file_get_contents($fullPath);
        if ($raw === false || trim($raw) === '') {
            throw new InvalidArgumentException("Snapshot file [{$name}] is empty.");
        }

        $data = json_decode($raw, true);
        if (json_last_error() !== JSON_ERROR_NONE || ! is_array($data)) {
            throw new InvalidArgumentException("Malformed JSON in snapshot [{$name}]: ".json_last_error_msg());
        }

        $errors = $this->validateSnapshot($data);
        if (! empty($errors)) {
            throw new InvalidArgumentException("Invalid snapshot format in [{$name}]: ".implode('; ', $errors));
        }

        return $data;
    }

    /**
     * Validate snapshot format and version metadata.
     */
    public function validateSnapshot(array $snapshot): array
    {
        $errors = [];

        if (isset($snapshot['version']) && $snapshot['version'] !== self::SUPPORTED_VERSION) {
            $errors[] = "Unsupported snapshot version [{$snapshot['version']}]. Supported version is [".self::SUPPORTED_VERSION.']';
        }

        if (! isset($snapshot['tables']) || ! is_array($snapshot['tables'])) {
            $errors[] = "Missing or invalid 'tables' definition in snapshot";
        }

        return $errors;
    }

    /**
     * Validate whether a table definition can be restored without loss.
     */
    public function canRestoreWithoutLoss(array $definition): bool
    {
        return empty($this->getLossyFeatures($definition));
    }

    /**
     * Inspect a table definition and return any unsupported or lossy schema features.
     */
    public function getLossyFeatures(array $definition): array
    {
        $issues = [];

        if (! isset($definition['columns']) || ! is_array($definition['columns']) || empty($definition['columns'])) {
            $issues[] = 'Missing or empty columns list';

            return $issues;
        }

        $seenColumns = [];
        foreach ($definition['columns'] as $column) {
            if (! is_array($column) || ! isset($column['name'], $column['type_name'])) {
                $issues[] = 'Malformed column metadata';

                continue;
            }

            $colName = (string) $column['name'];
            if (isset($seenColumns[$colName])) {
                $issues[] = "Duplicate column definition detected for [{$colName}]";
            }
            $seenColumns[$colName] = true;
            if (! preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/D', $colName)) {
                $issues[] = "Unsafe or invalid column identifier: [{$colName}]";
            }

            $typeName = (string) $column['type_name'];
            if (! $this->isSupportedDatabaseType($typeName)) {
                $issues[] = "Unsupported column type [{$typeName}] on column [{$colName}]";
            }
        }

        if (isset($definition['indexes']) && is_array($definition['indexes'])) {
            foreach ($definition['indexes'] as $index) {
                if (! is_array($index)) {
                    $issues[] = 'Malformed index metadata';

                    continue;
                }

                // Non-primary secondary indexes must declare at least one column
                if (empty($index['primary']) && (empty($index['columns']) || ! is_array($index['columns']))) {
                    $issues[] = 'Malformed index metadata';
                }
            }
        }

        if (isset($definition['foreign_keys']) && is_array($definition['foreign_keys'])) {
            foreach ($definition['foreign_keys'] as $fk) {
                if (! is_array($fk) || empty($fk['foreign_table']) || empty($fk['columns']) || empty($fk['foreign_columns'])) {
                    $issues[] = 'Malformed foreign key definition';
                }
            }
        }

        return $issues;
    }

    /**
     * Restore a table structure from a snapshot definition.
     */
    public function restoreTable(string $tableName, array $definition, ?string $connection = null): bool
    {
        if (! $this->canRestoreWithoutLoss($definition)) {
            $this->lastError = 'Table definition contains lossy features: '.implode('; ', $this->getLossyFeatures($definition));

            return false;
        }

        $this->lastError = null;

        try {
            Schema::connection($connection)->create($tableName, function (Blueprint $table) use ($definition) {
                $primaryKeyColumns = [];
                foreach ($definition['indexes'] ?? [] as $index) {
                    if (! empty($index['primary'])) {
                        $primaryKeyColumns = (array) ($index['columns'] ?? []);
                        break;
                    }
                }

                // Fallback for SQLite drivers where getIndexes() reports empty columns for primary
                if (empty($primaryKeyColumns)) {
                    foreach ($definition['columns'] as $col) {
                        if (! empty($col['primary'])) {
                            $primaryKeyColumns[] = (string) $col['name'];
                        }
                    }
                }

                $hasAutoIncrement = false;
                foreach ($definition['columns'] as $column) {
                    $colName = (string) $column['name'];
                    $typeName = strtolower((string) $column['type_name']);
                    $autoIncrement = ! empty($column['auto_increment']);

                    // Only create incrementing columns when auto_increment is explicitly true
                    if ($autoIncrement && str_contains($typeName, 'int')) {
                        $hasAutoIncrement = true;
                        if (str_contains($typeName, 'bigint')) {
                            $table->bigIncrements($colName);
                        } elseif (str_contains($typeName, 'tinyint')) {
                            $table->tinyIncrements($colName);
                        } elseif (str_contains($typeName, 'smallint')) {
                            $table->smallIncrements($colName);
                        } elseif (str_contains($typeName, 'mediumint')) {
                            $table->mediumIncrements($colName);
                        } else {
                            $table->increments($colName);
                        }

                        continue;
                    }

                    $method = $this->mapType($typeName);
                    if ($method === 'string' && ! empty($column['length'])) {
                        $col = $table->string($colName, (int) $column['length']);
                    } elseif ($method === 'decimal' && isset($column['precision'], $column['scale'])) {
                        $col = $table->decimal($colName, (int) $column['precision'], (int) $column['scale']);
                    } else {
                        $col = $table->{$method}($colName);
                    }

                    if (! empty($column['unsigned']) && method_exists($col, 'unsigned')) {
                        $col->unsigned();
                    }

                    if (! empty($column['nullable'])) {
                        $col->nullable();
                    } else {
                        $col->nullable(false);
                    }

                    if (array_key_exists('default', $column) && $column['default'] !== null) {
                        $col->default($column['default']);
                    }
                }

                // Explicit Composite Primary Key
                if (count($primaryKeyColumns) > 1) {
                    $table->primary($primaryKeyColumns);
                } elseif (count($primaryKeyColumns) === 1 && ! $hasAutoIncrement) {
                    $table->primary($primaryKeyColumns);
                }

                // Secondary Indexes & Unique Constraints
                foreach ($definition['indexes'] ?? [] as $index) {
                    if (! empty($index['primary'])) {
                        continue;
                    }
                    $cols = (array) ($index['columns'] ?? []);
                    $indexName = isset($index['name']) ? (string) $index['name'] : null;
                    if (empty($cols)) {
                        continue;
                    }

                    // Internal SQLite auto-indexes for primary/unique constraints must not be recreated
                    if ($indexName !== null && str_starts_with($indexName, 'sqlite_autoindex_')) {
                        continue;
                    }

                    if (! empty($index['unique'])) {
                        $table->unique($cols, $indexName);
                    } else {
                        $table->index($cols, $indexName);
                    }
                }
            });

            return true;
        } catch (Exception $e) {
            $this->lastError = $e->getMessage();

            return false;
        }
    }

    /**
     * Restore foreign keys for a table (executed in a second pass).
     */
    public function restoreForeignKeys(string $tableName, array $definition, ?string $connection = null): bool
    {
        $foreignKeys = $definition['foreign_keys'] ?? [];
        if (empty($foreignKeys) || ! is_array($foreignKeys)) {
            return true;
        }

        try {
            Schema::connection($connection)->table($tableName, function (Blueprint $table) use ($foreignKeys) {
                $allowedActions = ['cascade', 'restrict', 'set null', 'no action', 'set default'];

                foreach ($foreignKeys as $fk) {
                    $cols = (array) ($fk['columns'] ?? []);
                    $foreignCols = (array) ($fk['foreign_columns'] ?? []);
                    $foreignTable = (string) ($fk['foreign_table'] ?? '');
                    $name = isset($fk['name']) ? (string) $fk['name'] : null;

                    if (empty($cols) || empty($foreignCols) || $foreignTable === '') {
                        continue;
                    }

                    $fkBlueprint = $table->foreign($cols, $name)
                        ->references($foreignCols)
                        ->on($foreignTable);

                    if (! empty($fk['on_delete'])) {
                        $action = strtolower((string) $fk['on_delete']);
                        if (in_array($action, $allowedActions, true)) {
                            $fkBlueprint->onDelete($action);
                        }
                    }

                    if (! empty($fk['on_update'])) {
                        $action = strtolower((string) $fk['on_update']);
                        if (in_array($action, $allowedActions, true)) {
                            $fkBlueprint->onUpdate($action);
                        }
                    }
                }
            });

            return true;
        } catch (Exception $e) {
            $this->lastError = $e->getMessage();

            return false;
        }
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

        $baseTables = array_keys($base['tables'] ?? []);
        $newTables = array_keys($new['tables'] ?? []);

        $diff['tables_added'] = array_values(array_diff($newTables, $baseTables));
        $diff['tables_removed'] = array_values(array_diff($baseTables, $newTables));

        $commonTables = array_intersect($baseTables, $newTables);

        foreach ($commonTables as $table) {
            $tableChanges = $this->compareTable($base['tables'][$table], $new['tables'][$table]);
            if (! empty($tableChanges)) {
                $diff['table_changes'][$table] = $tableChanges;
            }
        }

        return $diff;
    }

    protected function compareTable(array $base, array $new): array
    {
        $changes = [];

        // Compare Columns
        $baseCols = \collect($base['columns'] ?? [])->keyBy('name');
        $newCols = \collect($new['columns'] ?? [])->keyBy('name');

        $added = array_diff($newCols->keys()->toArray(), $baseCols->keys()->toArray());
        $removed = array_diff($baseCols->keys()->toArray(), $newCols->keys()->toArray());

        if (! empty($added)) {
            $changes['columns_added'] = array_values($added);
        }
        if (! empty($removed)) {
            $changes['columns_removed'] = array_values($removed);
        }

        foreach (array_intersect($baseCols->keys()->toArray(), $newCols->keys()->toArray()) as $colName) {
            $baseCol = $baseCols[$colName];
            $newCol = $newCols[$colName];

            $colChanges = [];

            if (($baseCol['type_name'] ?? null) !== ($newCol['type_name'] ?? null)) {
                $colChanges['type'] = ['from' => $baseCol['type_name'] ?? null, 'to' => $newCol['type_name'] ?? null];
            }

            if (($baseCol['nullable'] ?? null) !== ($newCol['nullable'] ?? null)) {
                $colChanges['nullable'] = ['from' => $baseCol['nullable'] ?? null, 'to' => $newCol['nullable'] ?? null];
            }

            if (($baseCol['default'] ?? null) !== ($newCol['default'] ?? null)) {
                $colChanges['default'] = ['from' => $baseCol['default'] ?? null, 'to' => $newCol['default'] ?? null];
            }

            if (! empty($colChanges)) {
                $changes['column_changes'][$colName] = $colChanges;
            }
        }

        // Compare Indexes
        $baseIndexes = \collect($base['indexes'] ?? [])->keyBy('name');
        $newIndexes = \collect($new['indexes'] ?? [])->keyBy('name');

        $addedIndexes = array_diff($newIndexes->keys()->toArray(), $baseIndexes->keys()->toArray());
        $removedIndexes = array_diff($baseIndexes->keys()->toArray(), $newIndexes->keys()->toArray());

        if (! empty($addedIndexes)) {
            $changes['indexes_added'] = array_values($addedIndexes);
        }
        if (! empty($removedIndexes)) {
            $changes['indexes_removed'] = array_values($removedIndexes);
        }

        return $changes;
    }
}
