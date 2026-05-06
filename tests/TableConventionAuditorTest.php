<?php

declare(strict_types=1);

namespace Clcbws\DatabaseLifecycleSuite\Tests;

use Clcbws\DatabaseLifecycleSuite\TableConventionAuditor;
use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;

class TableConventionAuditorTest extends TestCase
{
    protected TableConventionAuditor $auditor;

    protected function setUp(): void
    {
        parent::setUp();
        $this->auditor = new TableConventionAuditor();
    }

    public function test_it_detects_naming_convention_violations()
    {
        Schema::create('userProfile', function (Blueprint $table) {
            $table->id();
        });

        $violations = $this->auditor->audit();

        $this->assertNotEmpty($violations);
        $this->assertEquals('userProfile', $violations[0]['current']);
        $this->assertEquals('user_profiles', $violations[0]['expected']);
    }

    public function test_it_ignores_standard_table_names()
    {
        Schema::create('blog_posts', function (Blueprint $table) {
            $table->id();
        });

        $violations = $this->auditor->audit();

        $standardViolations = \collect($violations)->where('table', 'blog_posts');
        $this->assertCount(0, $standardViolations);
    }
}
