<?php

declare(strict_types=1);

namespace Clcbws\DatabaseLifecycleSuite\Traits;

use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

/**
 * Trait FiltersTables
 * 
 * Ensures that Schema::getTables() results are scoped to the current 
 * connection's database.
 */
trait FiltersTables
{
    /**
     * Get tables for the current connection, filtered by schema.
     *
     * @param string|null $connection
     * @return array
     */
    protected function getFilteredTables(?string $connection = null): array
    {
        $conn = DB::connection($connection);
        $dbName = $conn->getDatabaseName();
        $tables = Schema::connection($connection)->getTables();

        // SQLite doesn't support cross-database schema leaks in the same way 
        // as MySQL/PostgreSQL, and getDatabaseName() often returns the file path 
        // while getTables() returns 'main'. We skip filtering for SQLite.
        if ($conn->getDriverName() === 'sqlite') {
            return $tables;
        }
        
        return \collect($tables)
            ->filter(fn($table) => ($table['schema'] ?? null) === $dbName)
            ->values()
            ->toArray();
    }
}
