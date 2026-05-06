<?php

declare(strict_types=1);

namespace Clcbws\DatabaseLifecycleSuite\Tests;

use Clcbws\DatabaseLifecycleSuite\QueryAnalyzer;
use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;

class QueryAnalyzerTest extends TestCase
{
    protected QueryAnalyzer $analyzer;

    protected function setUp(): void
    {
        parent::setUp();
        $this->analyzer = new QueryAnalyzer();

        Schema::create('users', function (Blueprint $table) {
            $table->id();
            $table->string('email')->index();
        });
    }

    public function test_it_analyzes_select_queries()
    {
        $result = $this->analyzer->analyze("SELECT * FROM users WHERE email = 'test@example.com'");

        $this->assertArrayHasKey('driver', $result);
        $this->assertArrayHasKey('insights', $result);
        $this->assertNotEmpty($result['rows']);
    }

    public function test_it_detects_query_type_with_comments()
    {
        $query = "/* High priority */ SELECT * FROM users";
        $result = $this->analyzer->analyze($query);
        
        $this->assertArrayHasKey('insights', $result);
    }
}
