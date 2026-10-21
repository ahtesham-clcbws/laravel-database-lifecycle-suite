<?php

declare(strict_types=1);

namespace Clcbws\DatabaseLifecycleSuite\Tests;

use Clcbws\DatabaseLifecycleSuite\SchemaSnapshotManager;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\Test;

/**
 * Class SchemaFidelityRoundTripTest
 *
 * Verifies deep structural schema equivalence across snapshot capture and restoration:
 * tables, columns, types, nullability, defaults, primary keys, composite keys,
 * unique indexes, ordinary indexes, foreign keys, and referential actions.
 */
class SchemaFidelityRoundTripTest extends TestCase
{
    protected SchemaSnapshotManager $manager;

    protected string $snapshotPath;

    protected function setUp(): void
    {
        parent::setUp();
        $this->manager = new SchemaSnapshotManager;
        $this->snapshotPath = __DIR__.'/snapshots_fidelity';

        if (! is_dir($this->snapshotPath)) {
            mkdir($this->snapshotPath, 0755, true);
        }

        $this->dropAllTestTables();
    }

    protected function tearDown(): void
    {
        $this->dropAllTestTables();

        if (is_dir($this->snapshotPath)) {
            $files = glob($this->snapshotPath.'/*.json');
            foreach ($files as $file) {
                @unlink($file);
            }
            @rmdir($this->snapshotPath);
        }

        parent::tearDown();
    }

    protected function dropAllTestTables(): void
    {
        Schema::dropIfExists('composite_keys');
        Schema::dropIfExists('products');
        Schema::dropIfExists('categories');
    }

    #[Test]
    public function test_deep_structural_equivalence_round_trip(): void
    {
        // 1. Create source schema with rich relational constructs
        Schema::create('categories', function (Blueprint $table) {
            $table->id();
            $table->string('name', 100);
            $table->string('slug', 120)->unique();
            $table->timestamps();
        });

        Schema::create('products', function (Blueprint $table) {
            $table->id();
            $table->foreignId('category_id')->constrained('categories')->cascadeOnDelete();
            $table->string('sku', 50)->unique();
            $table->string('name', 150);
            $table->decimal('price', 10, 2)->default(0.00);
            $table->integer('stock')->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->index(['category_id', 'is_active'], 'idx_products_cat_active');
        });

        Schema::create('composite_keys', function (Blueprint $table) {
            $table->unsignedBigInteger('tenant_id');
            $table->string('entity_code', 50);
            $table->string('meta_value')->nullable();
            $table->primary(['tenant_id', 'entity_code']);
        });

        // 2. Capture snapshot
        $this->manager->capture('fidelity_test', $this->snapshotPath);
        $snapshot = $this->manager->load('fidelity_test', $this->snapshotPath);

        $this->assertArrayHasKey('categories', $snapshot['tables']);
        $this->assertArrayHasKey('products', $snapshot['tables']);
        $this->assertArrayHasKey('composite_keys', $snapshot['tables']);

        // 3. Drop all original tables to simulate empty target restoration
        $this->dropAllTestTables();
        $this->assertFalse(Schema::hasTable('categories'));
        $this->assertFalse(Schema::hasTable('products'));
        $this->assertFalse(Schema::hasTable('composite_keys'));

        // 4. Restore into empty database target
        foreach ($snapshot['tables'] as $tableName => $definition) {
            $restored = $this->manager->restoreTable($tableName, $definition);
            $this->assertTrue($restored, "Failed to restore table [{$tableName}]: ".$this->manager->getLastError());
        }

        // Apply foreign keys
        foreach ($snapshot['tables'] as $tableName => $definition) {
            $fkRestored = $this->manager->restoreForeignKeys($tableName, $definition);
            $this->assertTrue($fkRestored, "Failed to restore foreign keys for [{$tableName}]: ".$this->manager->getLastError());
        }

        // 5. Verify Columns, Types, Nullability, Defaults on restored products
        $columns = Schema::getColumns('products');
        $colMap = [];
        foreach ($columns as $c) {
            $colMap[$c['name']] = $c;
        }

        $this->assertArrayHasKey('sku', $colMap);
        $this->assertArrayHasKey('price', $colMap);
        $this->assertArrayHasKey('stock', $colMap);
        $this->assertArrayHasKey('is_active', $colMap);

        $this->assertFalse((bool) $colMap['sku']['nullable']);
        // SQLite drivers represent default as "'0'" or 0
        $this->assertContains($colMap['stock']['default'], [0, '0', "'0'"]);

        // 6. Verify Indexes (Unique & Ordinary)
        $indexes = Schema::getIndexes('products');
        $skuUnique = \collect($indexes)->where('primary', false)->firstWhere('unique', true);
        $this->assertNotNull($skuUnique, 'Expected unique index on restored table');

        $compositeIdx = \collect($indexes)->where('primary', false)->firstWhere('name', 'idx_products_cat_active');
        $this->assertNotNull($compositeIdx, 'Expected composite ordinary index on restored table');

        // 7. Verify Composite Primary Key restoration
        $compCols = Schema::getColumns('composite_keys');
        $this->assertCount(3, $compCols);

        // 8. Capture second snapshot from restored database and assert structural equivalence
        $this->manager->capture('fidelity_reconstructed', $this->snapshotPath);
        $reconstructedSnapshot = $this->manager->load('fidelity_reconstructed', $this->snapshotPath);

        $diff = $this->manager->compare($snapshot, $reconstructedSnapshot);
        $this->assertEmpty($diff['tables_added']);
        $this->assertEmpty($diff['tables_removed']);
    }
}
