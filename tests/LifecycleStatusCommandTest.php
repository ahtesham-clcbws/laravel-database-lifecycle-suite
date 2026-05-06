<?php

declare(strict_types=1);

namespace Clcbws\DatabaseLifecycleSuite\Tests;

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;

class LifecycleStatusCommandTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Schema::create('users', function (Blueprint $table) {
            $table->id();
            $table->string('email')->index();
        });
    }

    public function test_it_outputs_lifecycle_scorecard()
    {
        $this->artisan('db:lifecycle-status')
            ->expectsOutputToContain('Generating Database Lifecycle Scorecard')
            ->expectsOutputToContain('Connection Reachability')
            ->assertExitCode(0);
    }

    public function test_it_respects_database_flag()
    {
        $this->artisan('db:lifecycle-status', ['--database' => 'testing'])
            ->expectsOutputToContain('for [testing]')
            ->assertExitCode(0);
    }
}
