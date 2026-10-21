<?php

declare(strict_types=1);

namespace Clcbws\DatabaseLifecycleSuite;

use Exception;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use InvalidArgumentException;

/**
 * Class DataDriftDetector
 *
 * Compares data between two database connections to identify discrepancies.
 * Supports single and composite primary keys, bounded memory safety,
 * and deterministic column-level comparisons.
 */
class DataDriftDetector
{
    protected ?string $lastError = null;

    /**
     * Get the last synchronization error or conflict detail.
     */
    public function getLastError(): ?string
    {
        return $this->lastError;
    }

    /**
     * Check a specific table for data drift against a source connection.
     *
     * @param  string  $table  The table name to check.
     * @param  string  $sourceConnection  The source of truth connection.
     * @param  string|null  $liveConnection  The local/destination connection.
     * @param  int  $chunkSize  Chunk size for iteration.
     * @param  int  $maxDifferences  Maximum number of diffs to retain in memory.
     * @return array Summary of detected discrepancies.
     *
     * @throws Exception
     */
    public function checkTable(
        string $table,
        string $sourceConnection,
        ?string $liveConnection = null,
        int $chunkSize = 500,
        int $maxDifferences = 1000
    ): array {
        $liveConnection = $liveConnection ?: DB::getDefaultConnection();

        if (! Schema::connection($sourceConnection)->hasTable($table)) {
            throw new InvalidArgumentException("Table [{$table}] does not exist on source connection [{$sourceConnection}].");
        }

        if (! Schema::connection($liveConnection)->hasTable($table)) {
            throw new InvalidArgumentException("Table [{$table}] does not exist on destination connection [{$liveConnection}].");
        }

        $primaryKeys = $this->getPrimaryKeys($table, $sourceConnection);
        if (empty($primaryKeys)) {
            $primaryKeys = $this->getPrimaryKeys($table, $liveConnection);
        }

        if (empty($primaryKeys)) {
            throw new Exception("Table [{$table}] lacks a primary key. Deterministic drift comparison requires a primary key.");
        }

        $missing = [];
        $extra = [];
        $differing = [];
        $truncated = false;

        $sourceQuery = DB::connection($sourceConnection)->table($table);
        foreach ($primaryKeys as $pk) {
            $sourceQuery->orderBy($pk);
        }

        // Pass 1: Read source rows in chunks and compare with destination
        $sourceQuery->chunk($chunkSize, function ($rows) use (
            &$missing,
            &$differing,
            &$truncated,
            $liveConnection,
            $table,
            $primaryKeys,
            $maxDifferences
        ) {
            if ($truncated) {
                return false;
            }

            $liveRows = $this->fetchMatchingRows($table, $liveConnection, $primaryKeys, $rows);

            foreach ($rows as $row) {
                $keyString = $this->buildCompositeKey($row, $primaryKeys);
                $sourceArray = (array) $row;

                if (! isset($liveRows[$keyString])) {
                    $missing[$keyString] = $sourceArray;
                } else {
                    $liveArray = $liveRows[$keyString];
                    $colDiffs = $this->compareRowValues($sourceArray, $liveArray);
                    if (! empty($colDiffs)) {
                        $differing[$keyString] = [
                            'key' => $this->extractKeyValues($row, $primaryKeys),
                            'source' => $sourceArray,
                            'live' => $liveArray,
                            'diff' => $colDiffs,
                        ];
                    }
                }

                if ((count($missing) + count($differing)) >= $maxDifferences) {
                    $truncated = true;

                    return false;
                }
            }

            return true;
        });

        // Pass 2: Check for destination-only extra rows (up to remaining quota)
        if (! $truncated) {
            $liveQuery = DB::connection($liveConnection)->table($table);
            foreach ($primaryKeys as $pk) {
                $liveQuery->orderBy($pk);
            }

            $liveQuery->chunk($chunkSize, function ($rows) use (
                &$extra,
                &$truncated,
                $sourceConnection,
                $table,
                $primaryKeys,
                $missing,
                $differing,
                $maxDifferences
            ) {
                if ($truncated) {
                    return false;
                }

                $sourceRows = $this->fetchMatchingRows($table, $sourceConnection, $primaryKeys, $rows);

                foreach ($rows as $row) {
                    $keyString = $this->buildCompositeKey($row, $primaryKeys);
                    if (! isset($sourceRows[$keyString])) {
                        $extra[$keyString] = (array) $row;
                    }

                    if ((count($missing) + count($differing) + count($extra)) >= $maxDifferences) {
                        $truncated = true;

                        return false;
                    }
                }

                return true;
            });
        }

        return [
            'primary_keys' => $primaryKeys,
            'missing' => $missing,
            'extra' => $extra,
            'differing' => $differing,
            'truncated' => $truncated,
            'max_differences' => $maxDifferences,
        ];
    }

    /**
     * Safely sync a specific row from source to live database inside a transaction.
     * Supports both new signature ($table, $data, $dest, $pks) and legacy ($table, $source, $data, $dest).
     */
    public function syncRow(
        string $table,
        string|array $sourceOrData,
        mixed $arg3 = null,
        mixed $arg4 = null,
        ?array $expectedDestinationState = null
    ): bool {
        if (is_array($sourceOrData)) {
            $data = $sourceOrData;
            $destination = is_string($arg3) ? $arg3 : null;
            $primaryKeys = is_array($arg4) ? $arg4 : (is_array($arg3) ? $arg3 : null);
        } else {
            // Legacy signature: syncRow($table, $sourceConnection, $data, $liveConnection)
            $data = is_array($arg3) ? $arg3 : [];
            $destination = is_string($arg4) ? $arg4 : null;
            $primaryKeys = null;
        }

        $destination = $destination ?: DB::getDefaultConnection();
        $primaryKeys = $primaryKeys ?: $this->getPrimaryKeys($table, $destination);

        $this->lastError = null;

        if (empty($primaryKeys)) {
            $this->lastError = "No primary keys identified for table [{$table}].";

            return false;
        }

        try {
            DB::connection($destination)->transaction(function () use (
                $table,
                $data,
                $destination,
                $primaryKeys,
                $expectedDestinationState
            ) {
                $conditions = [];
                foreach ($primaryKeys as $pk) {
                    if (! array_key_exists($pk, $data)) {
                        throw new InvalidArgumentException("Missing primary key column [{$pk}] in data to sync.");
                    }
                    $conditions[$pk] = $data[$pk];
                }

                // Acquire exclusive pessimistic lock on the target row if it exists
                $existing = DB::connection($destination)->table($table)->where($conditions)->lockForUpdate()->first();

                // Concurrency verification: ensure destination row has not changed concurrently
                if ($expectedDestinationState !== null) {
                    if ($existing === null) {
                        throw new \RuntimeException('Stale baseline: destination row was deleted concurrently.');
                    }

                    $currentArray = (array) $existing;
                    $conflicts = $this->compareRowValues($expectedDestinationState, $currentArray);
                    if (! empty($conflicts)) {
                        $conflictFields = implode(', ', array_keys($conflicts));
                        throw new \RuntimeException("Concurrent modification detected: destination row was modified concurrently on columns [{$conflictFields}].");
                    }
                }

                if ($existing !== null) {
                    DB::connection($destination)->table($table)->where($conditions)->update($data);
                } else {
                    DB::connection($destination)->table($table)->insert($data);
                }
            });

            return true;
        } catch (\Throwable $e) {
            $this->lastError = $e->getMessage();

            return false;
        }
    }

    /**
     * Determine all primary key columns for a table.
     */
    public function getPrimaryKeys(string $table, string $connection): array
    {
        try {
            $indexes = Schema::connection($connection)->getIndexes($table);
            foreach ($indexes as $index) {
                if (! empty($index['primary'])) {
                    return (array) $index['columns'];
                }
            }
        } catch (Exception $e) {
            // Ignore error and fallback
        }

        return [];
    }

    /**
     * Fetch matching rows from a target connection using single or composite primary keys.
     */
    protected function fetchMatchingRows(string $table, string $connection, array $primaryKeys, iterable $rows): array
    {
        $mapped = [];

        if (count($primaryKeys) === 1) {
            $pk = $primaryKeys[0];
            $ids = [];
            foreach ($rows as $row) {
                $ids[] = $row->{$pk};
            }

            $results = DB::connection($connection)->table($table)->whereIn($pk, $ids)->get();
            foreach ($results as $result) {
                $key = $this->buildCompositeKey($result, $primaryKeys);
                $mapped[$key] = (array) $result;
            }
        } else {
            // Composite primary key matching
            $results = DB::connection($connection)->table($table)->where(function ($query) use ($primaryKeys, $rows) {
                foreach ($rows as $row) {
                    $query->orWhere(function ($sub) use ($primaryKeys, $row) {
                        foreach ($primaryKeys as $pk) {
                            $sub->where($pk, $row->{$pk});
                        }
                    });
                }
            })->get();

            foreach ($results as $result) {
                $key = $this->buildCompositeKey($result, $primaryKeys);
                $mapped[$key] = (array) $result;
            }
        }

        return $mapped;
    }

    protected function buildCompositeKey(object|array $row, array $primaryKeys): string
    {
        $values = $this->extractKeyValues($row, $primaryKeys);

        return json_encode($values, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE) ?: serialize($values);
    }

    protected function extractKeyValues(object|array $row, array $primaryKeys): array
    {
        $values = [];
        $arr = (array) $row;
        foreach ($primaryKeys as $pk) {
            $values[$pk] = $arr[$pk] ?? null;
        }

        return $values;
    }

    /**
     * Compare two row arrays and return specific column differences.
     */
    protected function compareRowValues(array $source, array $live): array
    {
        $diffs = [];
        $allKeys = array_unique(array_merge(array_keys($source), array_keys($live)));

        foreach ($allKeys as $key) {
            $srcVal = $source[$key] ?? null;
            $liveVal = $live[$key] ?? null;

            // Handle strict null comparison vs scalar representation
            if ($srcVal !== $liveVal && (string) $srcVal !== (string) $liveVal) {
                $diffs[$key] = [
                    'source' => $srcVal,
                    'live' => $liveVal,
                ];
            }
        }

        return $diffs;
    }
}
