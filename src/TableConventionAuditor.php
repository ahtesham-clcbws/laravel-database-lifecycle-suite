<?php

declare(strict_types=1);

namespace Clcbws\DatabaseLifecycleSuite;

use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;
use Clcbws\DatabaseLifecycleSuite\Traits\FiltersTables;
use Illuminate\Support\Str;

/**
 * Class TableConventionAuditor
 * 
 * Checks if database tables follow Laravel's naming conventions 
 * (snake_case and plural). Promotes consistency and discoverability.
 */
readonly class TableConventionAuditor
{
    use FiltersTables;
    /**
     * Audit table names for convention violations.
     *
     * @param string|null $connection The database connection to use.
     * @return array List of tables violating naming conventions.
     */
    public function audit(?string $connection = null): array
    {
        $violations = [];
        $tables = $this->getFilteredTables($connection);
        $prefix = DB::connection($connection)->getTablePrefix();

        foreach ($tables as $table) {
            $fullName = $table['name'];
            $shortName = $this->stripPrefix($fullName, $prefix);

            // Laravel convention: snake_case and plural
            $expected = Str::snake(Str::pluralStudly($shortName));

            if ($shortName !== $expected) {
                $violations[] = [
                    'table' => $fullName,
                    'current' => $shortName,
                    'expected' => $expected,
                ];
            }
        }

        return $violations;
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
