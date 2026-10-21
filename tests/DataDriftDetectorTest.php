<?php

declare(strict_types=1);

namespace Clcbws\DatabaseLifecycleSuite\Tests;

use Clcbws\DatabaseLifecycleSuite\DataDriftDetector;
use Exception;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use InvalidArgumentException;

class DataDriftDetectorTest extends TestCase
{
    protected DataDriftDetector $detector;

    protected function setUp(): void
    {
        parent::setUp();
        $this->detector = new DataDriftDetector;

        // Configure a second connection for source of truth
        config(['database.connections.source' => [
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => '',
        ]]);

        // Create table on both connections
        foreach (['testing', 'source'] as $conn) {
            Schema::connection($conn)->create('products', function (Blueprint $table) {
                $table->id();
                $table->string('sku');
                $table->integer('stock');
            });

            Schema::connection($conn)->create('role_permissions', function (Blueprint $table) {
                $table->unsignedBigInteger('role_id');
                $table->unsignedBigInteger('permission_id');
                $table->string('granted_by')->nullable();
                $table->primary(['role_id', 'permission_id']);
            });

            Schema::connection($conn)->create('no_keys', function (Blueprint $table) {
                $table->string('name');
            });
        }
    }

    protected function tearDown(): void
    {
        foreach (['testing', 'source'] as $conn) {
            Schema::connection($conn)->dropIfExists('products');
            Schema::connection($conn)->dropIfExists('role_permissions');
            Schema::connection($conn)->dropIfExists('no_keys');
        }
        parent::tearDown();
    }

    public function test_it_detects_missing_rows(): void
    {
        DB::connection('source')->table('products')->insert(['id' => 1, 'sku' => 'LAPTOP', 'stock' => 10]);

        $drift = $this->detector->checkTable('products', 'source', 'testing');

        $this->assertCount(1, $drift['missing']);
        $firstMissing = reset($drift['missing']);
        $this->assertEquals('LAPTOP', $firstMissing['sku']);
    }

    public function test_it_detects_differing_rows(): void
    {
        DB::connection('source')->table('products')->insert(['id' => 1, 'sku' => 'LAPTOP', 'stock' => 10]);
        DB::connection('testing')->table('products')->insert(['id' => 1, 'sku' => 'LAPTOP', 'stock' => 5]);

        $drift = $this->detector->checkTable('products', 'source', 'testing');

        $this->assertCount(1, $drift['differing']);
        $firstDiff = reset($drift['differing']);
        $this->assertEquals(10, $firstDiff['source']['stock']);
        $this->assertEquals(5, $firstDiff['live']['stock']);
        $this->assertArrayHasKey('stock', $firstDiff['diff']);
    }

    public function test_it_supports_composite_primary_keys(): void
    {
        DB::connection('source')->table('role_permissions')->insert([
            'role_id' => 1,
            'permission_id' => 10,
            'granted_by' => 'admin',
        ]);

        $drift = $this->detector->checkTable('role_permissions', 'source', 'testing');
        $this->assertCount(1, $drift['missing']);

        // Sync composite key row
        $synced = $this->detector->syncRow('role_permissions', [
            'role_id' => 1,
            'permission_id' => 10,
            'granted_by' => 'admin',
        ], 'testing', ['role_id', 'permission_id']);

        $this->assertTrue($synced);
        $this->assertDatabaseHas('role_permissions', ['role_id' => 1, 'permission_id' => 10], 'testing');
    }

    public function test_sync_row_detects_concurrent_modification_conflict(): void
    {
        DB::connection('source')->table('products')->insert(['id' => 50, 'sku' => 'PHONE', 'stock' => 100]);
        DB::connection('testing')->table('products')->insert(['id' => 50, 'sku' => 'PHONE', 'stock' => 20]);

        $drift = $this->detector->checkTable('products', 'source', 'testing');
        $firstDiff = reset($drift['differing']);

        // Concurrently update live row in testing database before sync runs
        DB::connection('testing')->table('products')->where('id', 50)->update(['stock' => 35]);

        // Attempt sync with the original live state expectation
        $synced = $this->detector->syncRow(
            'products',
            $firstDiff['source'],
            'testing',
            ['id'],
            $firstDiff['live']
        );

        // Must fail to sync because live state was modified concurrently
        $this->assertFalse($synced);
        $this->assertEquals(35, DB::connection('testing')->table('products')->where('id', 50)->value('stock'));
    }

    public function test_composite_keys_prevent_delimiter_collisions(): void
    {
        Schema::connection('testing')->create('composite_strings', function (Blueprint $table) {
            $table->string('tenant');
            $table->string('sub_id');
            $table->string('payload');
            $table->primary(['tenant', 'sub_id']);
        });
        Schema::connection('source')->create('composite_strings', function (Blueprint $table) {
            $table->string('tenant');
            $table->string('sub_id');
            $table->string('payload');
            $table->primary(['tenant', 'sub_id']);
        });

        // Insert colliding delimiter patterns: ("a:b", "c") vs ("a", "b:c")
        DB::connection('source')->table('composite_strings')->insert([
            ['tenant' => 'a:b', 'sub_id' => 'c', 'payload' => 'First'],
            ['tenant' => 'a', 'sub_id' => 'b:c', 'payload' => 'Second'],
        ]);

        $drift = $this->detector->checkTable('composite_strings', 'source', 'testing');
        $this->assertCount(2, $drift['missing']);

        // Confirm both distinct keys exist in the missing map
        $keys = array_keys($drift['missing']);
        $this->assertCount(2, array_unique($keys));

        Schema::connection('testing')->dropIfExists('composite_strings');
        Schema::connection('source')->dropIfExists('composite_strings');
    }

    public function test_it_rejects_tables_without_primary_key(): void
    {
        $this->expectException(Exception::class);
        $this->expectExceptionMessage('lacks a primary key');
        $this->detector->checkTable('no_keys', 'source', 'testing');
    }

    public function test_it_rejects_missing_tables(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->detector->checkTable('non_existent_table', 'source', 'testing');
    }

    public function test_it_enforces_bounded_memory_limits(): void
    {
        for ($i = 1; $i <= 20; $i++) {
            DB::connection('source')->table('products')->insert(['id' => $i, 'sku' => "SKU-{$i}", 'stock' => $i]);
        }

        $drift = $this->detector->checkTable('products', 'source', 'testing', 5, 10);
        $this->assertTrue($drift['truncated']);
        $this->assertCount(10, $drift['missing']);
    }

    public function test_command_strict_mode_fails_when_drift_found(): void
    {
        DB::connection('source')->table('products')->insert(['id' => 99, 'sku' => 'STRICT-ITEM', 'stock' => 1]);

        $this->artisan('db:data-drift', [
            '--source' => 'source',
            '--database' => 'testing',
            '--table' => 'products',
            '--strict' => true,
        ])
            ->expectsOutputToContain('data drift discrepancies detected')
            ->assertExitCode(1);
    }

    public function test_sync_row_detects_concurrent_modification_and_reports_conflict(): void
    {
        DB::connection('testing')->table('products')->insert(['id' => 88, 'sku' => 'CONCURRENT-1', 'stock' => 5]);

        // Drift baseline thought live had stock 5
        $baseline = ['id' => 88, 'sku' => 'CONCURRENT-1', 'stock' => 5];
        $sourceData = ['id' => 88, 'sku' => 'CONCURRENT-1', 'stock' => 10];

        // Simulate concurrent modification on destination
        DB::connection('testing')->table('products')->where('id', 88)->update(['stock' => 999]);

        $success = $this->detector->syncRow('products', $sourceData, 'testing', ['id'], $baseline);

        $this->assertFalse($success);
        $this->assertNotNull($this->detector->getLastError());
        $this->assertStringContainsString('Concurrent modification detected', $this->detector->getLastError());
        $this->assertEquals(999, DB::connection('testing')->table('products')->where('id', 88)->value('stock'));
    }

    public function test_sync_row_detects_concurrent_deletion(): void
    {
        $baseline = ['id' => 77, 'sku' => 'DELETED-1', 'stock' => 2];
        $sourceData = ['id' => 77, 'sku' => 'DELETED-1', 'stock' => 5];

        // Row does not exist on destination
        $success = $this->detector->syncRow('products', $sourceData, 'testing', ['id'], $baseline);

        $this->assertFalse($success);
        $this->assertNotNull($this->detector->getLastError());
        $this->assertStringContainsString('Stale baseline: destination row was deleted concurrently', $this->detector->getLastError());
    }
}
