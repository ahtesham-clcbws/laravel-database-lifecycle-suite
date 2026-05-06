<?php

declare(strict_types=1);

namespace Clcbws\DatabaseLifecycleSuite;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Clcbws\DatabaseLifecycleSuite\Traits\FiltersTables;
use Exception;

/**
 * Class TableSizeReporter
 * 
 * Reports on the physical disk size and row count of database tables.
 * Supports MySQL, PostgreSQL, and SQLite with driver-specific optimizations.
 */
readonly class TableSizeReporter
{
    use FiltersTables;
    /**
     * Get size report for all tables on a connection.
     *
     * @param string|null $connection The database connection to use.
     * @return array List of tables with row counts and disk sizes.
     * @throws Exception If the database driver is not supported.
     */
    public function getReport(?string $connection = null): array
    {
        $connection = $connection ?: DB::getDefaultConnection();
        $driver = DB::connection($connection)->getDriverName();
        
        return match ($driver) {
            'mysql' => $this->getMySQLReport($connection),
            'pgsql' => $this->getPostgreSQLReport($connection),
            'sqlite' => $this->getSQLiteReport($connection),
            default => throw new Exception("Driver [{$driver}] is not supported for size reporting."),
        };
    }

    /**
     * Get size report for MySQL.
     *
     * @param string $connection
     * @return array
     */
    protected function getMySQLReport(string $connection): array
    {
        $database = DB::connection($connection)->getDatabaseName();
        $results = DB::connection($connection)->select("
            SELECT 
                table_name AS `table`,
                table_rows AS `rows`,
                round(((data_length + index_length) / 1024 / 1024), 2) AS `size_mb`
            FROM information_schema.TABLES
            WHERE table_schema = ?
            ORDER BY (data_length + index_length) DESC
        ", [$database]);

        return array_map(fn($row) => (array) $row, $results);
    }

    /**
     * Get size report for PostgreSQL.
     *
     * @param string $connection
     * @return array
     */
    protected function getPostgreSQLReport(string $connection): array
    {
        $results = DB::connection($connection)->select("
            SELECT 
                relname AS table,
                n_live_tup AS rows,
                round(pg_total_relation_size(relid) / 1024 / 1024, 2) AS size_mb
            FROM pg_stat_user_tables
            ORDER BY pg_total_relation_size(relid) DESC
        ");

        return array_map(fn($row) => (array) $row, $results);
    }

    /**
     * Get size report for SQLite.
     *
     * @param string $connection
     * @return array
     */
    protected function getSQLiteReport(string $connection): array
    {
        $tables = $this->getFilteredTables($connection);
        $report = [];

        foreach ($tables as $table) {
            $tableName = $table['name'];
            $report[] = [
                'table' => $tableName,
                'rows' => DB::connection($connection)->table($tableName)->count(),
                'size_mb' => 'N/A (SQLite file-based)',
            ];
        }

        return $report;
    }
}
