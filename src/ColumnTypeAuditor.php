<?php

declare(strict_types=1);

namespace Clcbws\DatabaseLifecycleSuite;

use Illuminate\Support\Facades\Schema;
use Clcbws\DatabaseLifecycleSuite\Traits\FiltersTables;

/**
 * Class ColumnTypeAuditor
 * 
 * Identifies columns with the same name across different tables that have 
 * inconsistent data types (e.g., user_id as int vs bigint).
 * Ensuring consistency across foreign keys and shared attributes is critical 
 * for performance and integrity.
 */
readonly class ColumnTypeAuditor
{
    use FiltersTables;
    /**
     * Audit database for column type inconsistencies.
     *
     * @param string|null $connection The database connection to use.
     * @return array List of columns with inconsistent types across tables.
     */
    public function audit(?string $connection = null): array
    {
        $columnsMap = [];
        $tables = $this->getFilteredTables($connection);

        foreach ($tables as $table) {
            $tableName = $table['name'];
            $columns = Schema::connection($connection)->getColumns($tableName);

            foreach ($columns as $column) {
                $columnsMap[$column['name']][] = [
                    'table' => $tableName,
                    'type' => $column['type_name'],
                ];
            }
        }

        $inconsistencies = [];
        foreach ($columnsMap as $columnName => $usages) {
            // We only care about columns that appear in more than one table
            if (count($usages) < 2) {
                continue;
            }

            $types = \collect($usages)->pluck('type')->unique();
            if ($types->count() > 1) {
                $inconsistencies[$columnName] = $usages;
            }
        }

        return $inconsistencies;
    }
}
