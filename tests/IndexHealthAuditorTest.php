<?php

declare(strict_types=1);

namespace Clcbws\DatabaseLifecycleSuite\Tests;

use Clcbws\DatabaseLifecycleSuite\IndexHealthAuditor;
use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;

class IndexHealthAuditorTest extends TestCase
{
    protected IndexHealthAuditor $auditor;

    protected function setUp(): void
    {
        parent::setUp();
        $this->auditor = new IndexHealthAuditor();

        Schema::create('users', function (Blueprint $table) {
            $table->id();
        });
    }

    public function test_it_detects_unindexed_foreign_keys()
    {
        Schema::create('posts', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('user_id');
            // We intentionally do NOT add an index on user_id
            $table->foreign('user_id')->references('id')->on('users');
        });

        $missing = $this->auditor->getMissingIndexes();

        $this->assertCount(1, $missing);
        $this->assertEquals('posts', $missing[0]['table']);
        $this->assertEquals('user_id', $missing[0]['column']);
    }

    public function test_it_ignores_properly_indexed_foreign_keys()
    {
        Schema::create('comments', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('user_id')->index(); // Properly indexed
            $table->foreign('user_id')->references('id')->on('users');
        });

        $missing = $this->auditor->getMissingIndexes();

        // Should not contain 'comments' table
        $commentViolations = \collect($missing)->where('table', 'comments');
        $this->assertCount(0, $commentViolations);
    }

    public function test_it_detects_potential_foreign_keys_by_naming_convention()
    {
        Schema::create('categories', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('parent_id'); // Looks like FK, but no index/FK defined
            $table->string('name');
        });

        $missing = $this->auditor->getMissingIndexes();

        $categoryViolations = \collect($missing)->where('table', 'categories');
        $this->assertCount(1, $categoryViolations);
        $this->assertEquals('parent_id', $categoryViolations->first()['column']);
        $this->assertEquals('Potential Foreign Key', $categoryViolations->first()['type']);
    }
}
