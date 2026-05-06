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
        $driver = $conn->getDriverName();
        $tables = Schema::connection($connection)->getTables();

        // The "Cross-Database Leak" is a specific behavior of the MySQL/MariaDB 
        // driver in Laravel 11/12. For other drivers (PostgreSQL, SQL Server, SQLite), 
        // we return the tables as-is to avoid accidental filtering of valid schemas.
        if (!in_array($driver, ['mysql', 'mariadb'])) {
            return $tables;
        }
        
        return \collect($tables)
            ->filter(fn($table) => ($table['schema'] ?? null) === $dbName)
            ->values()
            ->toArray();
    }
}
