<?php

declare(strict_types=1);

namespace Clcbws\DatabaseLifecycleSuite;

use Illuminate\Support\Facades\Schema;
use Clcbws\DatabaseLifecycleSuite\Traits\FiltersTables;

/**
 * Class IndexHealthAuditor
 * 
 * Audits the database schema to identify performance risks related to indexing.
 * Ensures foreign keys are properly indexed for performance.
 */
readonly class IndexHealthAuditor
{
    use FiltersTables;
    /**
     * Detect missing indexes on foreign key columns.
     * Accurate performance check: verifies if the FK column is the leading column 
     * in any index to ensure maximum query performance.
     *
     * @param string|null $connection The database connection to use.
     * @return array List of unindexed foreign key columns.
     */
    public function getMissingIndexes(?string $connection = null): array
    {
        $missing = [];
        $tables = $this->getFilteredTables($connection);

        foreach ($tables as $table) {
            $tableName = $table['name'];
            $foreignKeys = Schema::connection($connection)->getForeignKeys($tableName);
            $indexes = Schema::connection($connection)->getIndexes($tableName);
 
            // An FK column is only performant for JOINs/Deletes if it is the 
            // LEADING column in an index.
            $leadingColumns = \collect($indexes)->map(function ($index) {
                return $index['columns'][0] ?? null;
            })->filter()->unique()->toArray();
 
            foreach ($foreignKeys as $fk) {
                foreach ($fk['columns'] as $column) {
                    if (!in_array($column, $leadingColumns)) {
                        $missing[] = [
                            'table' => $tableName,
                            'column' => $column,
                            'type' => 'Formal Foreign Key',
                            'details' => "References {$fk['foreign_table']}.{$fk['foreign_columns'][0]}",
                        ];
                    }
                }
            }

            // Also check for columns following *_id convention that are not formal FKs but lack indexes
            $columns = Schema::connection($connection)->getColumns($tableName);
            $formalFkColumns = \collect($foreignKeys)->pluck('columns')->flatten()->toArray();

            foreach ($columns as $column) {
                $colName = $column['name'];
                if (str_ends_with($colName, '_id') && 
                    !in_array($colName, $formalFkColumns) && 
                    !in_array($colName, $leadingColumns) &&
                    $colName !== 'id'
                ) {
                    $missing[] = [
                        'table' => $tableName,
                        'column' => $colName,
                        'type' => 'Potential Foreign Key',
                        'details' => "Naming convention match (*_id)",
                    ];
                }
            }
        }

        return $missing;
    }
}
