<?php

declare(strict_types=1);

namespace Clcbws\DatabaseLifecycleSuite\Tests;

use Clcbws\DatabaseLifecycleSuite\TableSizeReporter;
use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;

class TableSizeReporterTest extends TestCase
{
    protected TableSizeReporter $reporter;

    protected function setUp(): void
    {
        parent::setUp();
        $this->reporter = new TableSizeReporter();

        Schema::create('logs', function (Blueprint $table) {
            $table->id();
            $table->text('message');
        });
    }

    public function test_it_reports_table_sizes()
    {
        $report = $this->reporter->getReport('testing');

        $this->assertNotEmpty($report);
        $this->assertEquals('logs', $report[0]['table']);
        $this->assertArrayHasKey('rows', $report[0]);
        $this->assertArrayHasKey('size_mb', $report[0]);
    }
}
