<?php

declare(strict_types=1);

namespace Clcbws\DatabaseLifecycleSuite\Tests;

use Clcbws\DatabaseLifecycleSuite\ColumnTypeAuditor;
use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;

class ColumnTypeAuditorTest extends TestCase
{
    protected ColumnTypeAuditor $auditor;

    protected function setUp(): void
    {
        parent::setUp();
        $this->auditor = new ColumnTypeAuditor();
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
}
