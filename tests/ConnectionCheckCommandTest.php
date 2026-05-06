<?php

declare(strict_types=1);

namespace Clcbws\DatabaseLifecycleSuite\Tests;

use Illuminate\Support\Facades\Artisan;

class ConnectionCheckCommandTest extends TestCase
{
    public function test_it_runs_connection_check_command()
    {
        $this->artisan('db:connection-check', ['--connections' => 'testing'])
            ->expectsOutputToContain('Pinging database connections')
            ->expectsOutputToContain('testing')
            ->assertExitCode(0);
    }
}
