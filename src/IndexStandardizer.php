<?php

declare(strict_types=1);

namespace Clcbws\DatabaseLifecycleSuite;

use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;
use Illuminate\Database\Schema\Blueprint;
use Clcbws\DatabaseLifecycleSuite\Traits\FiltersTables;
use Exception;

/**
 * Class IndexStandardizer
 * 
 * Enforces Laravel's index naming conventions across the database.
 * Standardizes index names to the format: {table}_{column}_{type}
 */
readonly class IndexStandardizer
{
    use FiltersTables;
    /**
     * Identify all existing indexes that violate the naming convention.
     *
     * @param string|null $connection The database connection to use.
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

            foreach ($indexes as $index) {
                if ($index['primary']) {
                    continue;
                }

                $expected = $this->calculateExpectedName($shortTableName, $index['columns'], $index['unique']);
                
                if ($index['name'] !== $expected) {
                    $drifts[] = [
                        'table' => $fullTableName,
                        'actual' => $index['name'],
                        'expected' => $expected,
                    ];
                }
            }
        }

        return $drifts;
    }

    /**
     * Calculate the standard Laravel index name based on table and columns.
     *
     * @param string $table
     * @param array $columns
     * @param bool $unique
     * @return string
     */
    protected function calculateExpectedName(string $table, array $columns, bool $unique): string
    {
        $type = $unique ? 'unique' : 'index';
        return $table . '_' . implode('_', $columns) . '_' . $type;
    }

    /**
     * Rename an index to match the standard convention.
     *
     * @param string $table
     * @param string $from
     * @param string $to
     * @param string|null $connection
     * @return bool
     */
    public function renameIndex(string $table, string $from, string $to, ?string $connection = null): bool
    {
        try {
            $prefix = DB::connection($connection)->getTablePrefix();
            $shortName = $this->stripPrefix($table, $prefix);

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
     *
     * @param string $tableName
     * @param string $prefix
     * @return string
     */
    protected function stripPrefix(string $tableName, string $prefix): string
    {
        if (!empty($prefix) && str_starts_with($tableName, $prefix)) {
            return substr($tableName, strlen($prefix));
        }
        return $tableName;
    }
}
