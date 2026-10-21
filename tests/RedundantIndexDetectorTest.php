<?php

declare(strict_types=1);

namespace Clcbws\DatabaseLifecycleSuite\Tests;

use Clcbws\DatabaseLifecycleSuite\RedundantIndexDetector;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class RedundantIndexDetectorTest extends TestCase
{
    protected RedundantIndexDetector $detector;

    protected function setUp(): void
    {
        parent::setUp();
        $this->detector = new RedundantIndexDetector;
    }

    public function test_it_detects_redundant_prefix_indexes()
    {
        Schema::create('orders', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('user_id');
            $table->string('status');

            $table->index(['user_id'], 'orders_user_id_index');
            $table->index(['user_id', 'status'], 'orders_user_id_status_index');
        });

        $redundant = $this->detector->getRedundantIndexes();

        $this->assertNotEmpty($redundant, 'Redundant indexes should be detected.');
        $this->assertEquals('orders_user_id_index', $redundant[0]['redundant']);
    }

    public function test_it_does_not_mark_unique_index_redundant_when_covered_by_composite_unique(): void
    {
        Schema::create('accounts', function (Blueprint $table) {
            $table->id();
            $table->string('email');
            $table->unsignedBigInteger('tenant_id');

            // Unique constraint on email alone
            $table->unique(['email'], 'accounts_email_unique');
            // Composite unique on email + tenant_id
            $table->unique(['email', 'tenant_id'], 'accounts_email_tenant_unique');
        });

        $redundant = $this->detector->getRedundantIndexes();

        $accountFindings = \collect($redundant)->where('table', 'accounts');
        $this->assertCount(0, $accountFindings, 'Single-column unique constraint must never be reported as redundant by composite unique.');
    }
}
