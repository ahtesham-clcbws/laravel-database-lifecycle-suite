<?php

declare(strict_types=1);

namespace Clcbws\DatabaseLifecycleSuite\Tests;

use Clcbws\DatabaseLifecycleSuite\IndexStandardizer;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\Test;

/**
 * Class IndexStandardizerTest
 *
 * Verifies the logic for detecting, calculating, and fixing database index naming violations.
 */
class IndexStandardizerTest extends TestCase
{
    protected function tearDown(): void
    {
        Schema::dropIfExists('users');
        Schema::dropIfExists('posts');
        Schema::dropIfExists('long_table_names_for_testing_length_limits');
        parent::tearDown();
    }

    #[Test]
    public function it_detects_non_standard_index_names(): void
    {
        Schema::create('users', function (Blueprint $table) {
            $table->id();
            $table->string('email');
            $table->index('email', 'custom_email_index');
        });

        $standardizer = new IndexStandardizer;
        $drifts = $standardizer->getNamingDrifts();

        $this->assertCount(1, $drifts);
        $this->assertEquals('users', $drifts[0]['table']);
        $this->assertEquals('custom_email_index', $drifts[0]['actual']);
        $this->assertEquals('users_email_index', $drifts[0]['expected']);
    }

    #[Test]
    public function it_respects_custom_naming_pattern_from_config(): void
    {
        config(['database-lifecycle-suite.index_naming_pattern' => 'idx_{table}_{column}']);

        $standardizer = new IndexStandardizer;
        $expected = $standardizer->calculateExpectedName('orders', ['user_id', 'status'], false);

        $this->assertEquals('idx_orders_user_id_status', $expected);
    }

    #[Test]
    public function it_enforces_identifier_length_limit(): void
    {
        $standardizer = new IndexStandardizer;
        $expected = $standardizer->calculateExpectedName(
            'very_long_table_name_that_exceeds_normal_identifier_length_limits',
            ['very_long_column_name_one', 'very_long_column_name_two'],
            false
        );

        $this->assertLessThanOrEqual(63, strlen($expected));
    }

    #[Test]
    public function it_renames_indexes_correctly(): void
    {
        Schema::create('posts', function (Blueprint $table) {
            $table->id();
            $table->string('slug');
            $table->unique('slug', 'old_slug_unique');
        });

        $standardizer = new IndexStandardizer;
        $standardizer->renameIndex('posts', 'old_slug_unique', 'posts_slug_unique');

        $indexes = Schema::getIndexes('posts');
        $uniqueIndex = \collect($indexes)->firstWhere('unique', true);

        $this->assertNotNull($uniqueIndex);
        $this->assertEquals('posts_slug_unique', $uniqueIndex['name']);
    }
}
