<?php

declare(strict_types=1);

namespace Clcbws\DatabaseLifecycleSuite\Tests;

use Clcbws\DatabaseLifecycleSuite\QueryAnalyzer;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
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
        $this->assertEquals(DB::connection()->getDriverName(), $result['driver']);
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
        $this->expectExceptionMessage('Unsafe');
        $this->analyzer->analyze('DELETE FROM users WHERE id = 1');
    }

    public function test_it_rejects_modifying_ctes(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Unsafe modifying keyword [DELETE]');
        $this->analyzer->analyze('WITH deleted AS (DELETE FROM users WHERE id = 1 RETURNING *) SELECT * FROM deleted');
    }

    public function test_it_rejects_comments_masking_modifications(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Unsafe');
        $this->analyzer->analyze('/* SELECT */ DROP TABLE users');
    }

    public function test_it_rejects_line_comment_masking(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Unsafe');
        $this->analyzer->analyze("-- SELECT\nUPDATE users SET email = 'hacked@test.com'");
    }

    public function test_it_allows_semicolons_inside_string_literals(): void
    {
        $result = $this->analyzer->analyze("SELECT * FROM users WHERE nickname = 'hello; world'");
        $this->assertNotEmpty($result['rows']);
    }

    public function test_it_allows_modifying_words_inside_string_literals(): void
    {
        $result = $this->analyzer->analyze("SELECT * FROM users WHERE nickname = 'DELETED'");
        $this->assertNotEmpty($result['rows']);
    }

    public function test_it_supports_read_only_ctes(): void
    {
        $query = 'WITH active_users AS (SELECT * FROM users WHERE email IS NOT NULL) SELECT * FROM active_users';
        $result = $this->analyzer->analyze($query);
        $this->assertNotEmpty($result['rows']);
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

    public function test_it_rejects_into_outfile_injection(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Unsafe modifying keyword [INTO] detected');
        $this->analyzer->analyze("SELECT * FROM users INTO OUTFILE '/tmp/dump.txt'");
    }

    public function test_it_rejects_null_bytes(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Unsafe query containing null bytes');
        $this->analyzer->analyze("SELECT * FROM users\0 WHERE id = 1");
    }

    public function test_it_rejects_unsupported_driver(): void
    {
        config()->set('database.connections.unsupported_db', [
            'driver' => 'sqlsrv',
            'database' => 'test',
        ]);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Unsupported database driver [sqlsrv]');
        $this->analyzer->analyze('SELECT * FROM users', 'unsupported_db');
    }

    public function test_it_permits_backtick_quoted_identifiers(): void
    {
        $driver = DB::connection()->getDriverName();
        if ($driver === 'pgsql') {
            $this->markTestSkipped('PostgreSQL uses standard double quotes for identifiers, not MySQL backticks.');
        }

        $result = $this->analyzer->analyze('SELECT `id`, `email` FROM `users` WHERE `email` = \'test@example.com\'');
        $this->assertNotEmpty($result['rows']);
    }

    public function test_it_permits_double_quoted_identifiers(): void
    {
        $driver = DB::connection()->getDriverName();
        if (in_array($driver, ['mysql', 'mariadb'], true)) {
            $this->markTestSkipped('MySQL/MariaDB requires ANSI_QUOTES mode to use double quotes as table identifiers.');
        }

        $result = $this->analyzer->analyze('SELECT "id", "email" FROM "users" WHERE "email" = \'test@example.com\'');
        $this->assertNotEmpty($result['rows']);
    }

    public function test_it_rejects_unmatched_quotations(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Unmatched or unclosed quotation detected');
        $this->analyzer->analyze("SELECT * FROM users WHERE email = 'unclosed");
    }

    public function test_it_rejects_unbalanced_parentheses(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Unbalanced parenthesis detected');
        $this->analyzer->analyze('SELECT * FROM users WHERE (id = 1');
    }

    public function test_it_rejects_administrative_and_pragma_queries(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Unsafe statement type [PRAGMA]');
        $this->analyzer->analyze('PRAGMA table_info(users)');
    }

    public function test_it_rejects_with_cte_without_terminating_select(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('WITH CTE must terminate in a read-only SELECT statement');
        $this->analyzer->analyze('WITH temp_users AS (SELECT * FROM users)');
    }

    public function test_it_rejects_dollar_quoted_strings(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Dollar-quoted strings ($$...$$) are not permitted');
        $this->analyzer->analyze('SELECT $$dangerous$$ FROM users');
    }

    public function test_it_rejects_for_update_locking_clause(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Row-level or table-level locking clauses (FOR UPDATE/SHARE) are not permitted');
        $this->analyzer->analyze('SELECT * FROM users WHERE id = 1 FOR UPDATE');
    }

    public function test_it_rejects_lock_in_share_mode(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Row-level or table-level locking clauses (FOR UPDATE/SHARE) are not permitted');
        $this->analyzer->analyze('SELECT * FROM users WHERE id = 1 LOCK IN SHARE MODE');
    }

    public function test_it_rejects_transaction_control_statements(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Unsafe statement type [COMMIT]');
        $this->analyzer->analyze('COMMIT');
    }

    public function test_it_rejects_sleep_and_benchmark_functions(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Unsafe modifying keyword [SLEEP] detected');
        $this->analyzer->analyze('SELECT * FROM users WHERE id = 1 AND SLEEP(5)');
    }

    public function test_it_rejects_recursive_ctes(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Recursive CTEs (WITH RECURSIVE) are not permitted');
        $this->analyzer->analyze('WITH RECURSIVE subordinates AS (SELECT * FROM users) SELECT * FROM subordinates');
    }

    public function test_it_rejects_unclosed_block_comment(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Unclosed block comment detected');
        $this->analyzer->analyze('SELECT * FROM users /* unclosed comment');
    }

    public function test_it_rejects_advisory_lock_functions(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Unsafe modifying keyword [GET_LOCK] detected');
        $this->analyzer->analyze("SELECT GET_LOCK('test_lock', 10)");
    }

    public function test_it_rejects_unlock_tables_command(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Unsafe statement type [UNLOCK]');
        $this->analyzer->analyze('UNLOCK TABLES');
    }

    public function test_it_preserves_supported_read_only_union_and_subqueries(): void
    {
        $result = $this->analyzer->analyze('SELECT id, email FROM users WHERE id IN (SELECT id FROM users WHERE id > 0) UNION ALL SELECT id, email FROM users WHERE id = 1');
        $this->assertIsArray($result);
        $this->assertNotEmpty($result['rows']);
    }
}
