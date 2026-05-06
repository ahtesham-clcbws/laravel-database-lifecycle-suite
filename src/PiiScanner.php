<?php

declare(strict_types=1);

namespace Clcbws\DatabaseLifecycleSuite;

use Illuminate\Support\Facades\Schema;
use Clcbws\DatabaseLifecycleSuite\Traits\FiltersTables;

/**
 * Class PiiScanner
 * 
 * Scans the database schema for columns that potentially contain Personally 
 * Identifiable Information (PII) based on naming patterns defined in configuration.
 * Useful for GDPR/CCPA compliance audits.
 */
readonly class PiiScanner
{
    use FiltersTables;
    /**
     * Scan the database for columns matching PII patterns.
     *
     * @param string|null $connection The database connection to use.
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
                $columnName = $column['name'];
                
                foreach ($patterns as $category => $pattern) {
                    if (preg_match((string) $pattern, $columnName)) {
                        $findings[] = [
                            'table' => $tableName,
                            'column' => $columnName,
                            'category' => ucfirst(str_replace('_', ' ', (string) $category)),
                        ];
                        break; // Move to next column after first match
                    }
                }
            }
        }

        return $findings;
    }
}
