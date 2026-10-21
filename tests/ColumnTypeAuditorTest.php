<?php

declare(strict_types=1);

namespace Clcbws\DatabaseLifecycleSuite\Tests;

use Clcbws\DatabaseLifecycleSuite\ColumnTypeAuditor;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class ColumnTypeAuditorTest extends TestCase
{
    protected ColumnTypeAuditor $auditor;

    protected function setUp(): void
    {
        parent::setUp();
        $this->auditor = new ColumnTypeAuditor;
    }

    public function test_it_detects_inconsistent_column_types()
    {
        Schema::create('users', function (Blueprint $table) {
            $table->string('shared_id'); // string
        });

        Schema::create('profiles', function (Blueprint $table) {
            $table->integer('shared_id'); // integer (mismatch)
        });

        $inconsistencies = $this->auditor->audit();

        $this->assertArrayHasKey('shared_id', $inconsistencies);
        $this->assertCount(2, $inconsistencies['shared_id']);
    }

    public function test_column_type_audit_command_respects_strict(): void
    {
        Schema::create('orders', function (Blueprint $table) {
            $table->string('ref_id');
        });
        Schema::create('invoices', function (Blueprint $table) {
            $table->integer('ref_id');
        });

        $this->artisan('db:column-type-audit', ['--strict' => true])
            ->expectsOutputToContain('inconsistent column types')
            ->assertExitCode(1);
    }
}
