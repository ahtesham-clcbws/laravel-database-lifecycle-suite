<?php

declare(strict_types=1);

namespace Clcbws\DatabaseLifecycleSuite;

use Illuminate\Support\Facades\DB;
use Exception;

/**
 * Class QueryAnalyzer
 * 
 * Analyzes SQL queries (SELECT, INSERT, UPDATE, DELETE) using EXPLAIN and 
 * provides human-readable performance insights.
 */
readonly class QueryAnalyzer
{
    /**
     * Analyze a SQL query using EXPLAIN.
     *
     * @param string $query The SQL query to analyze.
     * @param string|null $connection The database connection to use.
     * @return array Analysis results including driver, raw rows, and insights.
     * @throws Exception If the query is not supported or if analysis fails.
     */
    public function analyze(string $query, ?string $connection = null): array
    {
        try {
            $driver = DB::connection($connection)->getDriverName();
            
            // Extract the first word of the query, skipping comments and whitespace
            $cleanQuery = preg_replace('!/\*.*?\*/!s', '', $query); // Remove block comments
            $cleanQuery = preg_replace('/--.*$/m', '', (string) $cleanQuery); // Remove line comments
            
            if (preg_match('/^\s*([a-z]+)/i', (string) $cleanQuery, $matches)) {
                $queryType = strtoupper($matches[1]);
            } else {
                throw new Exception("Unable to determine query type.");
            }
            
            $allowedTypes = ['SELECT', 'INSERT', 'UPDATE', 'DELETE'];
            
            if (!in_array($queryType, $allowedTypes)) {
                throw new Exception("Query analysis is only supported for: " . implode(', ', $allowedTypes));
            }

            $results = DB::connection($connection)->select("EXPLAIN " . $query);

            return [
                'driver' => $driver,
                'rows' => $results,
                'insights' => $this->generateInsights($results, $driver),
            ];
        } catch (Exception $e) {
            throw new Exception("Failed to analyze query: " . $e->getMessage());
        }
    }

    /**
     * Generate human-readable insights from EXPLAIN results.
     *
     * @param array $results
     * @param string $driver
     * @return array
     */
    protected function generateInsights(array $results, string $driver): array
    {
        $insights = [];

        foreach ($results as $row) {
            $row = (array) $row;

            if ($driver === 'mysql') {
                if (isset($row['type']) && $row['type'] === 'ALL') {
                    $insights[] = "⚠️  Full Table Scan detected on [{$row['table']}]. No index was used.";
                }
                if (isset($row['rows']) && (int)$row['rows'] > 1000) {
                    $insights[] = "ℹ️  Large number of rows scanned (" . $row['rows'] . ") on [{$row['table']}].";
                }
                if (isset($row['key']) && $row['key']) {
                    $insights[] = "✅ Using index [{$row['key']}] for table [{$row['table']}].";
                }
                if (isset($row['Extra']) && str_contains($row['Extra'], 'Using temporary')) {
                    $insights[] = "⚠️  Query uses a temporary table (potential performance bottleneck).";
                }
                if (isset($row['Extra']) && str_contains($row['Extra'], 'Using filesort')) {
                    $insights[] = "⚠️  Query uses filesort (potential performance bottleneck on large sets).";
                }
            } elseif ($driver === 'pgsql') {
                $plan = (string) array_values($row)[0];
                if (str_contains($plan, 'Seq Scan')) {
                    $insights[] = "⚠️  Sequential Scan (Full Table Scan) detected in plan.";
                }
                if (str_contains($plan, 'Index Scan') || str_contains($plan, 'Index Only Scan')) {
                    $insights[] = "✅ Index Scan detected in plan.";
                }
                if (str_contains($plan, 'Sort Method: quicksort')) {
                    $insights[] = "✅ Optimized sort detected.";
                }
            }
        }

        return $insights;
    }
}
