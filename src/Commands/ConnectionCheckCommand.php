<?php

declare(strict_types=1);

namespace Clcbws\DatabaseLifecycleSuite\Commands;

use Illuminate\Console\Command;
use Clcbws\DatabaseLifecycleSuite\ConnectionHealthChecker;

/**
 * Class ConnectionCheckCommand
 * 
 * Artisan command to verify connectivity to all configured database connections.
 */
class ConnectionCheckCommand extends Command
{
    /**
     * @var string
     */
    protected $signature = 'db:connection-check {--connections= : Comma-separated list of connections to check}';

    /**
     * @var string
     */
    protected $description = 'Verify all configured database connections are reachable and measure latency';

    /**
     * Execute the console command.
     *
     * @param ConnectionHealthChecker $checker
     * @return int
     */
    public function handle(ConnectionHealthChecker $checker): int
    {
        $this->info('🔍 Pinging database connections...');

        $connections = $this->option('connections')
            ? explode(',', $this->option('connections'))
            : array_keys(\config('database.connections'));

        $report = $checker->check($connections);

        $this->table(['Connection', 'Status', 'Latency', 'Driver'], $report);

        return 0;
    }
}
