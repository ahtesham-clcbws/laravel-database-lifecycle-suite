<?php

declare(strict_types=1);

namespace Clcbws\DatabaseLifecycleSuite\Tests;

use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Clcbws\DatabaseLifecycleSuite\IndexStandardizer;
use PHPUnit\Framework\Attributes\Test;

/**
 * Class IndexStandardizerTest
 * 
 * Verifies the logic for detecting and fixing database index naming violations.
 */
class IndexStandardizerTest extends TestCase
{
    #[Test]
    public function it_detects_non_standard_index_names(): void
    {
        // Create a table with a non-standard index name
        Schema::create('users', function (Blueprint $table) {
            $table->id();
            $table->string('email');
            $table->index('email', 'custom_email_index');
        });

        $standardizer = new IndexStandardizer();
        $drifts = $standardizer->getNamingDrifts();

        // Verify the drift is detected correctly
        $this->assertCount(1, $drifts);
        $this->assertEquals('users', $drifts[0]['table']);
        $this->assertEquals('custom_email_index', $drifts[0]['actual']);
        $this->assertEquals('users_email_index', $drifts[0]['expected']);
    }

    #[Test]
    public function it_renames_indexes_correctly(): void
    {
        // Setup table with a legacy index name
        Schema::create('posts', function (Blueprint $table) {
            $table->id();
            $table->string('slug');
            $table->unique('slug', 'old_slug_unique');
        });

        $standardizer = new IndexStandardizer();
        $standardizer->renameIndex('posts', 'old_slug_unique', 'posts_slug_unique');

        // Check the database to see if the index was actually renamed
        $indexes = Schema::getIndexes('posts');
        
        // Use Laravel collection to find our unique index
        $uniqueIndex = \collect($indexes)->firstWhere('unique', true);
        
        $this->assertNotNull($uniqueIndex);
        $this->assertEquals('posts_slug_unique', $uniqueIndex['name']);
    }
}
