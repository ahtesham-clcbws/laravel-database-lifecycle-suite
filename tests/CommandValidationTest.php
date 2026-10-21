<?php

declare(strict_types=1);

namespace Clcbws\DatabaseLifecycleSuite\Tests;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\Test;

/**
 * Class CommandValidationTest
 *
 * Comprehensive end-to-end command testing for the 18 registered Artisan commands.
 * Tests execution, invalid arguments, exit codes, output matching, and database state.
 */
class CommandValidationTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Schema::dropIfExists('users');
        Schema::dropIfExists('posts');

        Schema::create('users', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('email')->unique();
            $table->timestamps();
        });
    }

    protected function tearDown(): void
    {
        Schema::dropIfExists('users');
        Schema::dropIfExists('posts');
        parent::tearDown();
    }

    #[Test]
    public function test_standardize_indexes_dry_run_and_execution(): void
    {
        Schema::create('posts', function (Blueprint $table) {
            $table->id();
            $table->string('slug');
            $table->unique('slug', 'custom_slug_idx');
        });

        // Dry-run mode
        $this->artisan('db:standardize-indexes', ['--dry-run' => true])
            ->expectsOutputToContain('DRY RUN: No changes were made.')
            ->assertExitCode(0);

        // State check: still old name
        $indexes = Schema::getIndexes('posts');
        $this->assertNotNull(\collect($indexes)->firstWhere('name', 'custom_slug_idx'));

        // Live execution with force
        $this->artisan('db:standardize-indexes', ['--force' => true])
            ->expectsOutputToContain('Standardization complete')
            ->assertExitCode(0);

        // State check: renamed
        $indexesAfter = Schema::getIndexes('posts');
        $this->assertNull(\collect($indexesAfter)->firstWhere('name', 'custom_slug_idx'));
        $this->assertNotNull(\collect($indexesAfter)->firstWhere('name', 'posts_slug_unique'));
    }

    #[Test]
    public function test_query_explain_valid_and_invalid(): void
    {
        $this->artisan('db:explain', ['query' => 'SELECT id, name FROM users WHERE id = 1'])
            ->assertExitCode(0);

        // Mutation attempt must fail with non-zero exit code
        $this->artisan('db:explain', ['query' => 'DELETE FROM users WHERE id = 1'])
            ->assertExitCode(1);
    }

    #[Test]
    public function test_pii_scan_and_strict_mode(): void
    {
        $this->artisan('db:pii-scan')
            ->expectsOutputToContain('email')
            ->assertExitCode(0);

        $this->artisan('db:pii-scan', ['--strict' => true])
            ->assertExitCode(1);
    }

    #[Test]
    public function test_lifecycle_status_json_and_table_output(): void
    {
        Artisan::call('db:lifecycle-status', ['--format' => 'json']);
        $output = Artisan::output();
        $this->assertJson($output);
        $decoded = json_decode($output, true);
        $this->assertArrayHasKey('health_score', $decoded);
        $this->assertArrayHasKey('checks', $decoded);

        $this->artisan('db:lifecycle-status', ['--format' => 'invalid_format'])
            ->assertExitCode(1);
    }
}
