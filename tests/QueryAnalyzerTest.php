<?php

declare(strict_types=1);

namespace Clcbws\DatabaseLifecycleSuite\Tests;

use Clcbws\DatabaseLifecycleSuite\QueryAnalyzer;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use InvalidArgumentException;

class QueryAnalyzerTest extends TestCase
{
    protected QueryAnalyzer $analyzer;

    protected function setUp(): void
    {
        parent::setUp();
        $this->analyzer = new QueryAnalyzer;

        Schema::create('users', function (Blueprint $table) {
            $table->id();
            $table->string('email')->index();
            $table->string('nickname');
        });
    }

    protected function tearDown(): void
    {
        Schema::dropIfExists('users');
        parent::tearDown();
    }

    public function test_it_analyzes_select_queries_with_index(): void
    {
        $result = $this->analyzer->analyze("SELECT * FROM users WHERE email = 'test@example.com'");

        $this->assertArrayHasKey('driver', $result);
        $this->assertArrayHasKey('insights', $result);
        $this->assertNotEmpty($result['rows']);
        $this->assertEquals('sqlite', $result['driver']);
    }

    public function test_it_detects_query_type_with_comments(): void
    {
        $query = '/* High priority */ SELECT * FROM users';
        $result = $this->analyzer->analyze($query);

        $this->assertArrayHasKey('insights', $result);
        $this->assertNotEmpty($result['rows']);
    }

    public function test_it_rejects_modifying_queries(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Unsafe query rejected');
        $this->analyzer->analyze('DELETE FROM users WHERE id = 1');
    }

    public function test_it_rejects_multiple_statements(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Multiple SQL statements are strictly rejected');
        $this->analyzer->analyze('SELECT * FROM users; DROP TABLE users;');
    }

    public function test_it_generates_sqlite_scan_insight_for_unindexed_column(): void
    {
        $result = $this->analyzer->analyze("SELECT * FROM users WHERE nickname = 'john'");
        $insightsString = implode(' ', $result['insights']);
        $this->assertStringContainsString('Full Table Scan', $insightsString);
    }
}
