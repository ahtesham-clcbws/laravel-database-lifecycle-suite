<?php

declare(strict_types=1);

namespace Clcbws\DatabaseLifecycleSuite;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Exception;

/**
 * Class DataDriftDetector
 * 
 * Compares data between two database connections to identify discrepancies.
 * Automatically detects primary keys and uses chunking for memory safety on large tables.
 */
readonly class DataDriftDetector
{
    /**
     * Check a specific table for data drift against a source connection.
     *
     * @param string $table The table name to check.
     * @param string $sourceConnection The name of the connection acting as the source of truth.
     * @param string|null $liveConnection The local connection to compare.
     * @param int $chunkSize Number of rows to process at once for memory safety.
     * @return array Summary of detected discrepancies (missing, extra, differing).
     */
    public function checkTable(string $table, string $sourceConnection, ?string $liveConnection = null, int $chunkSize = 1000): array
    {
        $liveConnection = $liveConnection ?: DB::getDefaultConnection();
        $primaryKey = $this->getPrimaryKey($table, $liveConnection);

        $missing = [];
        $extra = [];
        $differing = [];

        // Process source data in chunks to compare with live
        DB::connection($sourceConnection)->table($table)->orderBy($primaryKey)->chunk($chunkSize, function ($rows) use (&$missing, &$differing, $liveConnection, $table, $primaryKey) {
            $ids = $rows->pluck($primaryKey)->toArray();
            $liveRows = DB::connection($liveConnection)->table($table)->whereIn($primaryKey, $ids)->get()->keyBy($primaryKey);

            foreach ($rows as $row) {
                $id = $row->{$primaryKey};

                if (!$liveRows->has($id)) {
                    $missing[$id] = (array) $row;
                } elseif ((array) $row != (array) $liveRows->get($id)) {
                    $differing[$id] = [
                        'source' => (array) $row,
                        'live' => (array) $liveRows->get($id),
                    ];
                }
            }
        });

        // Find extra rows in live that don't exist in source
        DB::connection($liveConnection)->table($table)->orderBy($primaryKey)->chunk($chunkSize, function ($rows) use (&$extra, $sourceConnection, $table, $primaryKey) {
            $ids = $rows->pluck($primaryKey)->toArray();
            $sourceRows = DB::connection($sourceConnection)->table($table)->whereIn($primaryKey, $ids)->get()->keyBy($primaryKey);

            foreach ($rows as $row) {
                $id = $row->{$primaryKey};
                if (!$sourceRows->has($id)) {
                    $extra[$id] = (array) $row;
                }
            }
        });

        return [
            'primary_key' => $primaryKey,
            'missing' => $missing,
            'extra' => $extra,
            'differing' => $differing,
        ];
    }

    /**
     * Sync a specific row from source to live database.
     *
     * @param string $table
     * @param string $sourceConnection
     * @param array $data
     * @param string|null $liveConnection
     * @return bool
     */
    public function syncRow(string $table, string $sourceConnection, array $data, ?string $liveConnection = null): bool
    {
        $liveConnection = $liveConnection ?: DB::getDefaultConnection();
        $primaryKey = $this->getPrimaryKey($table, $liveConnection);

        try {
            DB::connection($liveConnection)->table($table)->updateOrInsert(
                [$primaryKey => $data[$primaryKey]], 
                $data
            );
            return true;
        } catch (Exception $e) {
            return false;
        }
    }

    /**
     * Determine the primary key column for a table.
     *
     * @param string $table
     * @param string $connection
     * @return string
     */
    protected function getPrimaryKey(string $table, string $connection): string
    {
        try {
            $indexes = Schema::connection($connection)->getIndexes($table);
            foreach ($indexes as $index) {
                if ($index['primary']) {
                    return $index['columns'][0];
                }
            }
        } catch (Exception $e) {
            // Default to id if lookup fails
        }
        
        return 'id';
    }
}
