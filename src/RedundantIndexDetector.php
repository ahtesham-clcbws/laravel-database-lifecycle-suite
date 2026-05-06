<?php

declare(strict_types=1);

namespace Clcbws\DatabaseLifecycleSuite;

use Illuminate\Support\Facades\Schema;
use Clcbws\DatabaseLifecycleSuite\Traits\FiltersTables;

/**
 * Class RedundantIndexDetector
 * 
 * Identifies indexes that are redundant because they are a prefix of 
 * another existing index on the same table.
 * Removing redundant indexes improves write performance and reduces disk usage.
 */
readonly class RedundantIndexDetector
{
    use FiltersTables;
    /**
     * Get all redundant indexes on a connection.
     *
     * @param string|null $connection The database connection to use.
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
            usort($indexes, fn($a, $b) => count($b['columns']) <=> count($a['columns']));

            for ($i = 0; $i < count($indexes); $i++) {
                for ($j = 0; $j < count($indexes); $j++) {
                    if ($i === $j) {
                        continue;
                    }

                    $longer = $indexes[$i];
                    $shorter = $indexes[$j];

                    if ($this->isRedundant($shorter, $longer)) {
                        $redundant[] = [
                            'table' => $tableName,
                            'redundant' => $shorter['name'],
                            'covered_by' => $longer['name'],
                            'columns' => implode(', ', $shorter['columns']),
                        ];
                    }
                }
            }
        }

        return $redundant;
    }

    /**
     * Check if index A is redundant because of index B.
     *
     * @param array $a
     * @param array $b
     * @return bool
     */
    protected function isRedundant(array $a, array $b): bool
    {
        // A primary key is never considered redundant in this context
        if ($a['primary']) {
            return false;
        }

        // If A is unique but B is not, A is not redundant (it enforces uniqueness)
        if ($a['unique'] && !$b['unique']) {
            return false;
        }

        $colsA = $a['columns'];
        $colsB = $b['columns'];

        // A is redundant if it has fewer or equal columns than B AND its columns 
        // are the starting columns of B.
        if (count($colsA) >= count($colsB) && $a['name'] !== $b['name']) {
            // But if they are identical, one is redundant.
            if ($colsA === $colsB) {
                return true;
            }
            return false;
        }

        // Check if $colsA is a prefix of $colsB
        return array_slice($colsB, 0, count($colsA)) === $colsA;
    }
}
