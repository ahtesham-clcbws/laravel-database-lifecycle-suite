<?php

declare(strict_types=1);

namespace Clcbws\DatabaseLifecycleSuite\Tests;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schema;

class LifecycleStatusCommandTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config(['database-lifecycle-suite.data_audit_tables' => []]);

        Schema::create('users', function (Blueprint $table) {
            $table->id();
            $table->string('email')->index();
        });
    }

    protected function tearDown(): void
    {
        Schema::dropIfExists('users');
        parent::tearDown();
    }

    public function test_it_outputs_lifecycle_scorecard_with_not_configured_and_skipped(): void
    {
        $this->artisan('db:lifecycle-status')
            ->expectsOutputToContain('Database Lifecycle Scorecard')
            ->expectsOutputToContain('Connection Reachability')
            ->expectsOutputToContain('NOT CONFIGURED')
            ->expectsOutputToContain('SKIPPED')
            ->expectsOutputToContain('Overall Database Health Score')
            ->assertExitCode(0);
    }

    public function test_it_respects_database_flag(): void
    {
        $this->artisan('db:lifecycle-status', ['--database' => 'testing'])
            ->expectsOutputToContain('for [testing]')
            ->assertExitCode(0);
    }

    public function test_it_outputs_valid_json_format(): void
    {
        Artisan::call('db:lifecycle-status', ['--format' => 'json']);
        $output = Artisan::output();

        $this->assertJson($output);

        $decoded = json_decode($output, true);
        $this->assertArrayHasKey('health_score', $decoded);
        $this->assertArrayHasKey('checks', $decoded);
        $this->assertIsInt($decoded['health_score']);

        $aspects = \collect($decoded['checks'])->pluck('aspect')->toArray();
        $this->assertContains('Connection Reachability', $aspects);
        $this->assertContains('Reference Data Consistency', $aspects);

        $dataCheck = \collect($decoded['checks'])->firstWhere('aspect', 'Reference Data Consistency');
        $this->assertEquals('NOT CONFIGURED', $dataCheck['status']);
    }
}
