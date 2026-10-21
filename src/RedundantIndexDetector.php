<?php

declare(strict_types=1);

namespace Clcbws\DatabaseLifecycleSuite;

use Clcbws\DatabaseLifecycleSuite\Traits\FiltersTables;
use Illuminate\Support\Facades\Schema;

/**
 * Class RedundantIndexDetector
 *
 * Identifies indexes that are redundant because they are covered by another
 * index on the same table, while safely protecting unique constraints and primary keys.
 */
readonly class RedundantIndexDetector
{
    use FiltersTables;

    /**
     * Get all redundant indexes on a connection.
     *
     * @param  string|null  $connection  The database connection to use.
     * @return array List of redundant index findings.
     */
    public function getRedundantIndexes(?string $connection = null): array
    {
        $redundant = [];
        $tables = $this->getFilteredTables($connection);

        foreach ($tables as $table) {
            $tableName = $table['name'];
            $indexes = Schema::connection($connection)->getIndexes($tableName);

            // Sort indexes by column count descending so we compare longer ones first
            usort($indexes, fn ($a, $b) => count($b['columns']) <=> count($a['columns']));

            $count = count($indexes);
            for ($i = 0; $i < $count; $i++) {
                for ($j = 0; $j < $count; $j++) {
                    if ($i === $j) {
                        continue;
                    }

                    $longer = $indexes[$i];
                    $shorter = $indexes[$j];

                    if ($this->isRedundant($shorter, $longer)) {
                        $redundant[] = [
                            'table' => $tableName,
                            'redundant' => (string) $shorter['name'],
                            'covered_by' => (string) $longer['name'],
                            'columns' => implode(', ', (array) $shorter['columns']),
                        ];
                    }
                }
            }
        }

        // De-duplicate findings
        $uniqueFindings = [];
        foreach ($redundant as $item) {
            $key = $item['table'].':'.$item['redundant'];
            if (! isset($uniqueFindings[$key])) {
                $uniqueFindings[$key] = $item;
            }
        }

        return array_values($uniqueFindings);
    }

    /**
     * Check if index A is redundant because of index B.
     */
    public function isRedundant(array $a, array $b): bool
    {
        // A primary key is never considered redundant
        if (! empty($a['primary'])) {
            return false;
        }

        $colsA = array_values((array) ($a['columns'] ?? []));
        $colsB = array_values((array) ($b['columns'] ?? []));

        // If A is unique, it is NOT redundant just because B starts with the same columns,
        // because composite unique constraints do NOT enforce uniqueness of the prefix alone.
        // A is only redundant if B is also unique or primary on the EXACT same columns.
        if (! empty($a['unique'])) {
            if ($colsA === $colsB && (! empty($b['unique']) || ! empty($b['primary'])) && $a['name'] !== $b['name']) {
                return true;
            }

            return false;
        }

        // If identical columns but different names, one is redundant
        if ($colsA === $colsB && ($a['name'] ?? '') !== ($b['name'] ?? '')) {
            return true;
        }

        // If A has fewer columns than B, check if colsA is an exact leading prefix of colsB
        if (count($colsA) < count($colsB)) {
            return array_slice($colsB, 0, count($colsA)) === $colsA;
        }

        return false;
    }
}
