<?php

declare(strict_types=1);

namespace Clcbws\DatabaseLifecycleSuite\Tests;

use Clcbws\DatabaseLifecycleSuite\ConnectionHealthChecker;

class ConnectionHealthCheckerTest extends TestCase
{
    protected ConnectionHealthChecker $checker;

    protected function setUp(): void
    {
        parent::setUp();
        $this->checker = new ConnectionHealthChecker;
    }

    public function test_it_checks_connection_health()
    {
        $conn = (string) config('database.default');
        $report = $this->checker->check([$conn]);

        $this->assertCount(1, $report);
        $this->assertEquals($conn, $report[0]['Connection']);
        $this->assertEquals('✅ Online', $report[0]['Status']);
    }

    public function test_it_handles_failed_connections()
    {
        $report = $this->checker->check(['invalid_connection']);

        $this->assertCount(1, $report);
        $this->assertEquals('invalid_connection', $report[0]['Connection']);
        $this->assertEquals('❌ Failed', $report[0]['Status']);
    }
}
