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

        $this->assertArrayHasKey('1', $drift['missing']);
        $this->assertEquals('LAPTOP', $drift['missing']['1']['sku']);
    }

    public function test_it_detects_differing_rows(): void
    {
        DB::connection('source')->table('products')->insert(['id' => 1, 'sku' => 'LAPTOP', 'stock' => 10]);
        DB::connection('testing')->table('products')->insert(['id' => 1, 'sku' => 'LAPTOP', 'stock' => 5]);

        $drift = $this->detector->checkTable('products', 'source', 'testing');

        $this->assertArrayHasKey('1', $drift['differing']);
        $this->assertEquals(10, $drift['differing']['1']['source']['stock']);
        $this->assertEquals(5, $drift['differing']['1']['live']['stock']);
        $this->assertArrayHasKey('stock', $drift['differing']['1']['diff']);
    }

    public function test_it_supports_composite_primary_keys(): void
    {
        DB::connection('source')->table('role_permissions')->insert([
            'role_id' => 1,
            'permission_id' => 10,
            'granted_by' => 'admin',
        ]);

        $drift = $this->detector->checkTable('role_permissions', 'source', 'testing');
        $this->assertArrayHasKey('1:10', $drift['missing']);

        // Sync composite key row
        $synced = $this->detector->syncRow('role_permissions', [
            'role_id' => 1,
            'permission_id' => 10,
            'granted_by' => 'admin',
        ], 'testing', ['role_id', 'permission_id']);

        $this->assertTrue($synced);
        $this->assertDatabaseHas('role_permissions', ['role_id' => 1, 'permission_id' => 10], 'testing');
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
}
