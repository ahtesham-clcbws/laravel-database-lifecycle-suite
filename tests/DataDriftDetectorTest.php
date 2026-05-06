<?php

declare(strict_types=1);

namespace Clcbws\DatabaseLifecycleSuite\Tests;

use Clcbws\DatabaseLifecycleSuite\DataDriftDetector;
use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;

class DataDriftDetectorTest extends TestCase
{
    protected DataDriftDetector $detector;

    protected function setUp(): void
    {
        parent::setUp();
        $this->detector = new DataDriftDetector();

        // Configure a second connection for source of truth
        config(['database.connections.source' => [
            'driver'   => 'sqlite',
            'database' => ':memory:',
            'prefix'   => '',
        ]]);

        // Create table on both connections
        foreach (['testing', 'source'] as $conn) {
            Schema::connection($conn)->create('products', function (Blueprint $table) {
                $table->id();
                $table->string('sku');
                $table->integer('stock');
            });
        }
    }

    public function test_it_detects_missing_rows()
    {
        // Add row to source
        DB::connection('source')->table('products')->insert(['id' => 1, 'sku' => 'LAPTOP', 'stock' => 10]);

        $drift = $this->detector->checkTable('products', 'source', 'testing');

        $this->assertArrayHasKey(1, $drift['missing']);
        $this->assertEquals('LAPTOP', $drift['missing'][1]['sku']);
    }

    public function test_it_detects_differing_rows()
    {
        // Add row to both with different data
        DB::connection('source')->table('products')->insert(['id' => 1, 'sku' => 'LAPTOP', 'stock' => 10]);
        DB::connection('testing')->table('products')->insert(['id' => 1, 'sku' => 'LAPTOP', 'stock' => 5]);

        $drift = $this->detector->checkTable('products', 'source', 'testing');

        $this->assertArrayHasKey(1, $drift['differing']);
        $this->assertEquals(10, $drift['differing'][1]['source']['stock']);
        $this->assertEquals(5, $drift['differing'][1]['live']['stock']);
    }

    public function test_it_can_sync_a_row()
    {
        $data = ['id' => 1, 'sku' => 'PHONE', 'stock' => 20];
        
        $success = $this->detector->syncRow('products', 'source', $data, 'testing');

        $this->assertTrue($success);
        $this->assertDatabaseHas('products', ['sku' => 'PHONE', 'stock' => 20], 'testing');
    }
}
