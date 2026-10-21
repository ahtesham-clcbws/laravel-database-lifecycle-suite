<?php

declare(strict_types=1);

namespace Clcbws\DatabaseLifecycleSuite;

use Clcbws\DatabaseLifecycleSuite\Traits\FiltersTables;
use Illuminate\Support\Facades\Schema;

/**
 * Class PiiScanner
 *
 * Heuristically scans the database schema for column names that match
 * known Personally Identifiable Information (PII) patterns.
 * NOTE: Pattern matching is heuristic and does NOT verify underlying data content.
 */
readonly class PiiScanner
{
    use FiltersTables;

    /**
     * Scan the database for columns matching PII patterns.
     *
     * @param  string|null  $connection  The database connection to use.
     * @return array List of potential PII findings.
     */
    public function scan(?string $connection = null): array
    {
        $findings = [];
        $tables = $this->getFilteredTables($connection);
        $patterns = (array) \config('database-lifecycle-suite.pii_patterns', []);

        foreach ($tables as $table) {
            $tableName = $table['name'];
            $columns = Schema::connection($connection)->getColumns($tableName);

            foreach ($columns as $column) {
                $columnName = (string) $column['name'];

                foreach ($patterns as $category => $pattern) {
                    if (preg_match((string) $pattern, $columnName)) {
                        $findings[] = [
                            'table' => $tableName,
                            'column' => $columnName,
                            'category' => ucfirst(str_replace('_', ' ', (string) $category)),
                            'confidence' => 'Heuristic',
                            'details' => 'Matched pattern against column name',
                        ];
                        break;
                    }
                }
            }
        }

        return $findings;
    }
}
