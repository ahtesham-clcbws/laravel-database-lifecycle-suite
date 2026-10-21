<?php

declare(strict_types=1);

namespace Clcbws\DatabaseLifecycleSuite;

use Clcbws\DatabaseLifecycleSuite\Traits\FiltersTables;
use Illuminate\Support\Facades\Schema;

/**
 * Class IndexHealthAuditor
 *
 * Audits the database schema to identify missing indexes on foreign keys
 * and potential foreign key columns, distinguishing confirmed foreign keys
 * from convention-based candidates.
 */
readonly class IndexHealthAuditor
{
    use FiltersTables;

    /**
     * Detect missing indexes on foreign key columns.
     * Accurate performance check: verifies if the FK column is the leading column
     * in any index to ensure maximum query and join performance.
     *
     * @param  string|null  $connection  The database connection to use.
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

            // 1. Formal Foreign Keys (Confirmed)
            foreach ($foreignKeys as $fk) {
                $fkCols = (array) ($fk['columns'] ?? []);
                $foreignTable = (string) ($fk['foreign_table'] ?? '');
                $foreignCols = (array) ($fk['foreign_columns'] ?? ['id']);

                foreach ($fkCols as $column) {
                    if (! in_array($column, $leadingColumns, true)) {
                        $missing[] = [
                            'table' => $tableName,
                            'column' => $column,
                            'type' => 'Formal Foreign Key',
                            'details' => "References {$foreignTable}.".($foreignCols[0] ?? 'id'),
                            'is_confirmed' => true,
                        ];
                    }
                }
            }

            // 2. Potential Foreign Keys (Heuristic convention matching *_id)
            $columns = Schema::connection($connection)->getColumns($tableName);
            $formalFkColumns = \collect($foreignKeys)->pluck('columns')->flatten()->toArray();

            foreach ($columns as $column) {
                $colName = (string) $column['name'];
                if (str_ends_with($colName, '_id') &&
                    ! in_array($colName, $formalFkColumns, true) &&
                    ! in_array($colName, $leadingColumns, true) &&
                    $colName !== 'id'
                ) {
                    $missing[] = [
                        'table' => $tableName,
                        'column' => $colName,
                        'type' => 'Potential Foreign Key',
                        'details' => 'Naming convention match (*_id, unconstrained)',
                        'is_confirmed' => false,
                    ];
                }
            }
        }

        return $missing;
    }
}
