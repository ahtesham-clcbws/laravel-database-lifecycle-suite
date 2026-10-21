<?php

declare(strict_types=1);

namespace Clcbws\DatabaseLifecycleSuite;

use Exception;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Class QueryAnalyzer
 *
 * Provides safe, read-only query-plan inspection (EXPLAIN / EXPLAIN QUERY PLAN)
 * across MySQL, MariaDB, PostgreSQL, and SQLite with multi-driver performance insights.
 */
readonly class QueryAnalyzer
{
    /**
     * Analyze a SQL query using driver-appropriate EXPLAIN mechanics.
     *
     * @param  string  $query  The SQL query to analyze.
     * @param  string|null  $connection  The database connection to use.
     * @return array Analysis results including driver, raw rows, and insights.
     *
     * @throws Exception|InvalidArgumentException
     */
    public function analyze(string $query, ?string $connection = null): array
    {
        $driver = DB::connection($connection)->getDriverName();

        $supportedDrivers = ['sqlite', 'mysql', 'mariadb', 'pgsql'];
        if (! in_array($driver, $supportedDrivers, true)) {
            throw new InvalidArgumentException("Unsupported database driver [{$driver}] for query analysis. Supported drivers are: ".implode(', ', $supportedDrivers).'.');
        }

        $cleanQuery = $this->validateAndSanitizeQuery($query);

        // Execute driver-appropriate EXPLAIN
        try {
            $explainSql = match ($driver) {
                'sqlite' => 'EXPLAIN QUERY PLAN '.$cleanQuery,
                default => 'EXPLAIN '.$cleanQuery,
            };

            $results = DB::connection($connection)->select($explainSql);

            return [
                'driver' => $driver,
                'query' => $cleanQuery,
                'rows' => $results,
                'insights' => $this->generateInsights($results, $driver),
            ];
        } catch (Exception $e) {
            throw new Exception('Query plan analysis failed: '.$e->getMessage());
        }
    }

    /**
     * Validate and sanitize query ensuring strictly read-only execution.
     *
     * @throws InvalidArgumentException
     */
    public function validateAndSanitizeQuery(string $query): string
    {
        // 0. Reject null bytes or binary corruption
        if (str_contains($query, "\0")) {
            throw new InvalidArgumentException('Unsafe query containing null bytes.');
        }

        // 1. Remove comments safely
        $noComments = (string) preg_replace('!/\*.*?\*/!s', ' ', $query);
        $noComments = (string) preg_replace('/(--|#).*$/m', ' ', $noComments);
        $trimmed = trim($noComments);

        if ($trimmed === '') {
            throw new InvalidArgumentException('Empty SQL query provided for analysis.');
        }

        // 2. Normalize and mask string literals to prevent literal confusion
        // Mask single-quoted literals: '...' (escaped as '' or \')
        $masked = (string) preg_replace("/'(''|\\\\'|[^'])*'/", "'__STR__'", $trimmed);
        // Mask double-quoted strings: "..."
        $masked = (string) preg_replace('/"(""|\\\\"|[^"])*"/', '"__STR__"', $masked);
        // Mask backtick identifiers: `...`
        $masked = (string) preg_replace('/`([^`]|``)*`/', '`__ID__`', $masked);

        // 3. Reject multiple statements (semicolons outside string literals)
        $strippedSemicolons = rtrim($masked, "; \t\n\r\0\x0B");
        if (str_contains($strippedSemicolons, ';')) {
            throw new InvalidArgumentException('Multiple SQL statements are strictly rejected for safety.');
        }

        // 4. Validate statement opening: strictly SELECT or read-only WITH
        if (! preg_match('/^\s*([a-zA-Z]+)/', $masked, $matches)) {
            throw new InvalidArgumentException('Unable to determine query statement type.');
        }

        $queryType = strtoupper($matches[1]);
        if (! in_array($queryType, ['SELECT', 'WITH'], true)) {
            throw new InvalidArgumentException("Unsafe statement type [{$queryType}]. Only read-only SELECT and WITH statements are permitted.");
        }

        // 5. Reject modifying DDL / DML and filesystem/execution keywords anywhere outside string literals
        $modifyingKeywords = [
            'INSERT', 'UPDATE', 'DELETE', 'DROP', 'ALTER', 'TRUNCATE',
            'CREATE', 'REPLACE', 'MERGE', 'GRANT', 'REVOKE', 'LOCK',
            'CALL', 'EXEC', 'EXECUTE', 'VACUUM', 'COPY', 'INTO',
            'OUTFILE', 'DUMPFILE', 'LOAD_FILE', 'ANALYZE',
        ];

        $pattern = '/\b('.implode('|', $modifyingKeywords).')\b/i';
        if (preg_match($pattern, $masked, $keywordMatches)) {
            $keyword = strtoupper($keywordMatches[1]);
            throw new InvalidArgumentException("Unsafe modifying keyword [{$keyword}] detected. Modifying operations are strictly prohibited.");
        }

        // Return trimmed statement without trailing semicolon
        return rtrim($trimmed, "; \t\n\r\0\x0B");
    }

    /**
     * Generate human-readable performance insights based on execution plan.
     */
    protected function generateInsights(array $results, string $driver): array
    {
        $insights = [];

        foreach ($results as $row) {
            $row = (array) $row;

            if ($driver === 'sqlite') {
                $detail = (string) ($row['detail'] ?? '');
                if (str_contains($detail, 'SCAN')) {
                    $insights[] = "⚠️  Full Table Scan detected: {$detail}. Consider adding an index.";
                } elseif (str_contains($detail, 'SEARCH') && str_contains($detail, 'USING INDEX')) {
                    $insights[] = "✅ Index search utilized: {$detail}.";
                } elseif (str_contains($detail, 'USE TEMP B-TREE')) {
                    $insights[] = "⚠️  Temporary B-Tree utilized for sorting/grouping: {$detail}.";
                }
            } elseif ($driver === 'mysql' || $driver === 'mariadb') {
                $table = (string) ($row['table'] ?? '');
                $type = (string) ($row['type'] ?? '');
                $key = (string) ($row['key'] ?? '');
                $rows = (int) ($row['rows'] ?? 0);
                $extra = (string) ($row['Extra'] ?? '');

                if ($type === 'ALL') {
                    $insights[] = "⚠️  Full Table Scan detected on [{$table}]. No index was used.";
                } elseif ($key !== '') {
                    $insights[] = "✅ Using index [{$key}] for table [{$table}].";
                }

                if ($rows > 1000) {
                    $insights[] = "ℹ️  High row scan count ({$rows} rows) estimated for table [{$table}].";
                }

                if (str_contains($extra, 'Using temporary')) {
                    $insights[] = '⚠️  Query uses an internal temporary table on disk/memory.';
                }
                if (str_contains($extra, 'Using filesort')) {
                    $insights[] = '⚠️  Query uses filesort (potential sorting bottleneck on large datasets).';
                }
            } elseif ($driver === 'pgsql') {
                $plan = (string) array_values($row)[0];
                if (str_contains($plan, 'Seq Scan')) {
                    $insights[] = '⚠️  Sequential Scan (Full Table Scan) detected in PostgreSQL plan.';
                }
                if (str_contains($plan, 'Index Scan') || str_contains($plan, 'Index Only Scan') || str_contains($plan, 'Bitmap Index Scan')) {
                    $insights[] = '✅ Index Scan detected in plan.';
                }
                if (str_contains($plan, 'Sort Method: quicksort')) {
                    $insights[] = '✅ In-memory quicksort utilized for sorting.';
                }
                if (str_contains($plan, 'external merge')) {
                    $insights[] = '⚠️  Disk-based external merge sort detected (work_mem may be insufficient).';
                }
            }
        }

        if (empty($insights)) {
            $insights[] = 'ℹ️  Query plan generated successfully. No prominent performance bottlenecks detected.';
        }

        return array_values(array_unique($insights));
    }
}
