<?php

declare(strict_types=1);

namespace Clcbws\DatabaseLifecycleSuite;

use Illuminate\Support\Facades\DB;
use Exception;

/**
 * Class ConnectionHealthChecker
 * 
 * Verifies database connection availability and measures latency.
 * Provides a quick health diagnostic for multi-database architectures.
 */
readonly class ConnectionHealthChecker
{
    /**
     * Check health of specific database connections.
     *
     * @param array $connections List of connection names to check.
     * @return array List of health status and latency per connection.
     */
    public function check(array $connections): array
    {
        $report = [];

        foreach ($connections as $name) {
            $start = microtime(true);
            try {
                // Perform a lightweight query to verify the connection is alive
                DB::connection($name)->select('SELECT 1');
                
                $latency = (microtime(true) - $start) * 1000;
                
                $report[] = [
                    'Connection' => $name,
                    'Status' => '✅ Online',
                    'Latency' => round($latency, 2) . 'ms',
                    'Driver' => DB::connection($name)->getDriverName(),
                ];
            } catch (Exception $e) {
                $report[] = [
                    'Connection' => $name,
                    'Status' => '❌ Failed',
                    'Latency' => 'N/A',
                    'Driver' => (string) \config("database.connections.{$name}.driver", 'unknown'),
                ];
            }
        }

        return $report;
    }
}
