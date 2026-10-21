<?php

declare(strict_types=1);

namespace Clcbws\DatabaseLifecycleSuite;

use Clcbws\DatabaseLifecycleSuite\Traits\FiltersTables;
use Exception;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Class IndexStandardizer
 *
 * Enforces configurable index naming conventions across the database.
 * Supports pattern interpolation, identifier length limits, collision prevention,
 * and dry-run execution.
 */
readonly class IndexStandardizer
{
    use FiltersTables;

    /**
     * Identify all existing indexes that violate the naming convention.
     *
     * @param  string|null  $connection  The database connection to use.
     * @return array List of naming drifts with actual and expected names.
     */
    public function getNamingDrifts(?string $connection = null): array
    {
        $drifts = [];
        $tables = $this->getFilteredTables($connection);
        $prefix = DB::connection($connection)->getTablePrefix();

        foreach ($tables as $table) {
            $fullTableName = $table['name'];
            $shortTableName = $this->stripPrefix($fullTableName, $prefix);
            $indexes = Schema::connection($connection)->getIndexes($fullTableName);
            $existingNames = \collect($indexes)->pluck('name')->toArray();

            foreach ($indexes as $index) {
                if (! empty($index['primary'])) {
                    continue;
                }

                $columns = (array) $index['columns'];
                $unique = ! empty($index['unique']);
                $expected = $this->calculateExpectedName($shortTableName, $columns, $unique);
                $actual = (string) $index['name'];

                if ($actual !== $expected) {
                    $collision = in_array($expected, $existingNames, true) && $actual !== $expected;

                    $drifts[] = [
                        'table' => $fullTableName,
                        'actual' => $actual,
                        'expected' => $expected,
                        'columns' => $columns,
                        'unique' => $unique,
                        'collision' => $collision,
                    ];
                }
            }
        }

        return $drifts;
    }

    /**
     * Calculate the standard index name based on configured pattern and identifier length limits.
     */
    public function calculateExpectedName(string $table, array $columns, bool $unique): string
    {
        $pattern = (string) \config('database-lifecycle-suite.index_naming_pattern', '{table}_{column}_{type}');
        $type = $unique ? 'unique' : 'index';
        $columnStr = implode('_', $columns);

        $name = str_replace(
            ['{table}', '{column}', '{type}'],
            [$table, $columnStr, $type],
            $pattern
        );

        // Sanitize characters
        $name = (string) preg_replace('/[^a-zA-Z0-9_]/', '_', $name);

        // Cross-driver identifier limit: 63 characters (PostgreSQL limit is 63, MySQL is 64)
        if (strlen($name) > 63) {
            $hash = substr(md5($name), 0, 6);
            $name = substr($name, 0, 56).'_'.$hash;
        }

        return $name;
    }

    /**
     * Rename an index to match the standard convention.
     */
    public function renameIndex(string $table, string $from, string $to, ?string $connection = null): bool
    {
        if ($from === $to) {
            return true;
        }

        try {
            $prefix = DB::connection($connection)->getTablePrefix();
            $shortName = $this->stripPrefix($table, $prefix);

            // Check if destination name already exists on this table
            $indexes = Schema::connection($connection)->getIndexes($table);
            $names = \collect($indexes)->pluck('name')->toArray();
            if (in_array($to, $names, true)) {
                return false;
            }

            Schema::connection($connection)->table($shortName, function (Blueprint $blueprint) use ($from, $to) {
                $blueprint->renameIndex($from, $to);
            });

            return true;
        } catch (Exception $e) {
            return false;
        }
    }

    /**
     * Strip prefix from table name if it exists.
     */
    protected function stripPrefix(string $tableName, string $prefix): string
    {
        if (! empty($prefix) && str_starts_with($tableName, $prefix)) {
            return substr($tableName, strlen($prefix));
        }

        return $tableName;
    }
}
