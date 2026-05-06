<?php

declare(strict_types=1);

namespace Clcbws\DatabaseLifecycleSuite\Tests;

use Clcbws\DatabaseLifecycleSuite\RedundantIndexDetector;
use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;

class RedundantIndexDetectorTest extends TestCase
{
    protected RedundantIndexDetector $detector;

    protected function setUp(): void
    {
        parent::setUp();
        $this->detector = new RedundantIndexDetector();
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
}
