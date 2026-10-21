<?php

declare(strict_types=1);

namespace Clcbws\DatabaseLifecycleSuite\Tests;

class ConnectionCheckCommandTest extends TestCase
{
    public function test_it_runs_connection_check_command()
    {
        $conn = (string) config('database.default');
        $this->artisan('db:connection-check', ['--connections' => $conn])
            ->expectsOutputToContain('Pinging database connections')
            ->expectsOutputToContain($conn)
            ->assertExitCode(0);
    }
}
