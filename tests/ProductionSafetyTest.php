<?php

declare(strict_types=1);

namespace Clcbws\DatabaseLifecycleSuite\Tests;

use Clcbws\DatabaseLifecycleSuite\LegacyBridge;
use Clcbws\DatabaseLifecycleSuite\SchemaSnapshotManager;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use InvalidArgumentException;
use ReflectionMethod;

class ProductionSafetyTest extends TestCase
{
    public function test_snapshot_preflight_accepts_supported_schemas_with_indexes_and_foreign_keys(): void
    {
        $manager = new SchemaSnapshotManager;

        $definition = [
            'columns' => [
                ['name' => 'id', 'type_name' => 'bigint', 'nullable' => false, 'default' => null, 'auto_increment' => true],
                ['name' => 'title', 'type_name' => 'varchar', 'nullable' => false, 'default' => 'Default Title'],
                ['name' => 'notes', 'type_name' => 'text', 'nullable' => true, 'default' => null],
                ['name' => 'is_active', 'type_name' => 'boolean', 'nullable' => false, 'default' => 1],
                ['name' => 'user_id', 'type_name' => 'bigint', 'nullable' => false, 'default' => null],
            ],
            'indexes' => [
                ['name' => 'posts_user_id_index', 'columns' => ['user_id'], 'unique' => false, 'primary' => false],
                ['name' => 'posts_title_unique', 'columns' => ['title'], 'unique' => true, 'primary' => false],
            ],
            'foreign_keys' => [
                [
                    'name' => 'posts_user_id_foreign',
                    'foreign_table' => 'users',
                    'columns' => ['user_id'],
                    'foreign_columns' => ['id'],
                    'on_delete' => 'cascade',
                    'on_update' => 'restrict',
                ],
            ],
        ];

        $this->assertTrue($manager->canRestoreWithoutLoss($definition));
        $this->assertEmpty($manager->getLossyFeatures($definition));
    }

    public function test_snapshot_preflight_rejects_unsupported_columns(): void
    {
        $manager = new SchemaSnapshotManager;

        $definition = [
            'columns' => [
                ['name' => 'location', 'type_name' => 'geography_polygon_unknown', 'nullable' => true, 'default' => null],
            ],
            'indexes' => [],
            'foreign_keys' => [],
        ];

        $this->assertFalse($manager->canRestoreWithoutLoss($definition));
        $lossy = $manager->getLossyFeatures($definition);
        $this->assertNotEmpty($lossy);
        $this->assertStringContainsString('Unsupported column type', $lossy[0]);
    }

    public function test_snapshot_preflight_rejects_unsafe_column_names(): void
    {
        $manager = new SchemaSnapshotManager;

        $definition = [
            'columns' => [
                ['name' => 'col; DROP TABLE users;', 'type_name' => 'text', 'nullable' => true, 'default' => null],
            ],
            'indexes' => [],
            'foreign_keys' => [],
        ];

        $this->assertFalse($manager->canRestoreWithoutLoss($definition));
        $lossy = $manager->getLossyFeatures($definition);
        $this->assertStringContainsString('Unsafe or invalid column identifier', $lossy[0]);
    }

    public function test_snapshot_manager_rejects_path_traversal(): void
    {
        $manager = new SchemaSnapshotManager;

        $this->expectException(InvalidArgumentException::class);
        $manager->validateSnapshotName('../../etc/passwd');
    }

    public function test_snapshot_manager_rejects_malformed_json(): void
    {
        $manager = new SchemaSnapshotManager;
        $dir = sys_get_temp_dir().'/snap-test-'.bin2hex(random_bytes(4));
        mkdir($dir, 0755, true);
        file_put_contents($dir.'/bad.json', '{invalid json syntax}');

        try {
            $this->expectException(InvalidArgumentException::class);
            $this->expectExceptionMessage('Malformed JSON');
            $manager->load('bad', $dir);
        } finally {
            @unlink($dir.'/bad.json');
            @rmdir($dir);
        }
    }

    public function test_snapshot_manager_rejects_unsupported_version(): void
    {
        $manager = new SchemaSnapshotManager;
        $dir = sys_get_temp_dir().'/snap-ver-'.bin2hex(random_bytes(4));
        mkdir($dir, 0755, true);
        file_put_contents($dir.'/v99.json', json_encode([
            'version' => '99.0',
            'tables' => [],
        ]));

        try {
            $this->expectException(InvalidArgumentException::class);
            $this->expectExceptionMessage('Unsupported snapshot version');
            $manager->load('v99', $dir);
        } finally {
            @unlink($dir.'/v99.json');
            @rmdir($dir);
        }
    }

    public function test_snapshot_restoration_round_trip(): void
    {
        $manager = new SchemaSnapshotManager;

        Schema::create('articles', function (Blueprint $table) {
            $table->id();
            $table->string('slug')->unique();
            $table->text('body')->nullable();
            $table->timestamps();
        });

        $originalColumns = \collect(Schema::getColumns('articles'))->keyBy('name')->toArray();
        $originalIndexes = Schema::getIndexes('articles');

        $definition = [
            'columns' => Schema::getColumns('articles'),
            'indexes' => $originalIndexes,
            'foreign_keys' => Schema::getForeignKeys('articles'),
        ];

        Schema::drop('articles');
        $this->assertFalse(Schema::hasTable('articles'));

        $restored = $manager->restoreTable('articles', $definition);
        $this->assertTrue($restored, 'Failed restoring table: '.$manager->getLastError());
        $this->assertTrue(Schema::hasTable('articles'));

        // High fidelity verification: compare restored schema metadata against original
        $restoredColumns = \collect(Schema::getColumns('articles'))->keyBy('name')->toArray();
        $this->assertCount(count($originalColumns), $restoredColumns);

        foreach ($originalColumns as $colName => $origCol) {
            $this->assertArrayHasKey($colName, $restoredColumns);
            $restCol = $restoredColumns[$colName];
            $this->assertEquals((bool) $origCol['nullable'], (bool) $restCol['nullable'], "Nullable mismatch on [{$colName}]");
        }

        // Verify index fidelity: unique slug index is reconstructed
        $restoredIndexes = Schema::getIndexes('articles');
        $uniqueSlug = \collect($restoredIndexes)->first(function ($idx) {
            return ! empty($idx['unique']) && in_array('slug', (array) ($idx['columns'] ?? []), true);
        });
        $this->assertNotNull($uniqueSlug, 'Restored unique index on [slug] not found.');

        Schema::dropIfExists('articles');
    }

    public function test_full_schema_round_trip_with_foreign_keys_and_diverse_types(): void
    {
        $manager = new SchemaSnapshotManager;

        Schema::dropIfExists('employees');
        Schema::dropIfExists('departments');

        Schema::create('departments', function (Blueprint $table) {
            $table->id();
            $table->string('dept_code', 50)->unique();
            $table->string('name', 100);
            $table->boolean('is_active')->default(true);
            $table->integer('headcount')->default(0);
            $table->timestamps();
        });

        Schema::create('employees', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('department_id');
            $table->string('email', 150)->unique();
            $table->string('first_name', 50);
            $table->string('last_name', 50);
            $table->decimal('salary', 10, 2)->default(0.00);
            $table->text('bio')->nullable();
            $table->timestamps();

            $table->index(['last_name', 'first_name']);
            $table->foreign('department_id')->references('id')->on('departments')->onDelete('cascade');
        });

        $origDeptCols = \collect(Schema::getColumns('departments'))->keyBy('name')->toArray();
        $origDeptIndexes = Schema::getIndexes('departments');
        $origDeptFks = Schema::getForeignKeys('departments');

        $origEmpCols = \collect(Schema::getColumns('employees'))->keyBy('name')->toArray();
        $origEmpIndexes = Schema::getIndexes('employees');
        $origEmpFks = Schema::getForeignKeys('employees');

        $deptDef = [
            'columns' => Schema::getColumns('departments'),
            'indexes' => $origDeptIndexes,
            'foreign_keys' => $origDeptFks,
        ];

        $empDef = [
            'columns' => Schema::getColumns('employees'),
            'indexes' => $origEmpIndexes,
            'foreign_keys' => $origEmpFks,
        ];

        // Drop in reverse dependency order
        Schema::drop('employees');
        Schema::drop('departments');

        $this->assertFalse(Schema::hasTable('employees'));
        $this->assertFalse(Schema::hasTable('departments'));

        // Phase 1: Restore tables
        $this->assertTrue($manager->restoreTable('departments', $deptDef), 'Failed restoring departments: '.$manager->getLastError());
        $this->assertTrue($manager->restoreTable('employees', $empDef), 'Failed restoring employees: '.$manager->getLastError());

        // Phase 2: Restore foreign keys
        $this->assertTrue($manager->restoreForeignKeys('employees', $empDef), 'Failed restoring foreign keys: '.$manager->getLastError());

        // Assert table existence
        $this->assertTrue(Schema::hasTable('departments'));
        $this->assertTrue(Schema::hasTable('employees'));

        // Assert column fidelity
        $restoredDeptCols = \collect(Schema::getColumns('departments'))->keyBy('name')->toArray();
        $this->assertCount(count($origDeptCols), $restoredDeptCols);
        foreach ($origDeptCols as $name => $col) {
            $this->assertArrayHasKey($name, $restoredDeptCols);
            $this->assertEquals((bool) $col['nullable'], (bool) $restoredDeptCols[$name]['nullable'], "Dept column [{$name}] nullability mismatch");
        }

        $restoredEmpCols = \collect(Schema::getColumns('employees'))->keyBy('name')->toArray();
        $this->assertCount(count($origEmpCols), $restoredEmpCols);
        foreach ($origEmpCols as $name => $col) {
            $this->assertArrayHasKey($name, $restoredEmpCols);
            $this->assertEquals((bool) $col['nullable'], (bool) $restoredEmpCols[$name]['nullable'], "Emp column [{$name}] nullability mismatch");
        }

        // Assert index fidelity
        $restoredEmpIndexes = Schema::getIndexes('employees');
        $uniqueEmail = \collect($restoredEmpIndexes)->first(function ($idx) {
            return ! empty($idx['unique']) && in_array('email', (array) ($idx['columns'] ?? []), true);
        });
        $this->assertNotNull($uniqueEmail, 'Restored unique index on [email] not found.');

        $compositeNameIdx = \collect($restoredEmpIndexes)->first(function ($idx) {
            $cols = (array) ($idx['columns'] ?? []);

            return in_array('last_name', $cols, true) && in_array('first_name', $cols, true);
        });
        $this->assertNotNull($compositeNameIdx, 'Restored composite index on [last_name, first_name] not found.');

        // Assert foreign key fidelity if driver supports foreign key reflection
        $restoredEmpFks = Schema::getForeignKeys('employees');
        if (! empty($origEmpFks)) {
            $this->assertNotEmpty($restoredEmpFks, 'Restored foreign keys on employees table should not be empty.');
            $deptFk = \collect($restoredEmpFks)->first(function ($fk) {
                return ($fk['foreign_table'] ?? '') === 'departments';
            });
            $this->assertNotNull($deptFk, 'Restored foreign key pointing to [departments] not found.');
        }

        Schema::dropIfExists('employees');
        Schema::dropIfExists('departments');
    }

    public function test_snapshot_restoration_rejects_unsupported_metadata_before_changes(): void
    {
        $manager = new SchemaSnapshotManager;

        $unsupportedDef = [
            'columns' => [
                ['name' => 'id', 'type_name' => 'integer', 'nullable' => false, 'auto_increment' => true],
                ['name' => 'geo_blob', 'type_name' => 'unsupported_custom_geometry', 'nullable' => true],
            ],
            'indexes' => [
                ['name' => 'pk', 'columns' => ['id'], 'primary' => true],
            ],
            'foreign_keys' => [],
        ];

        $this->assertFalse($manager->canRestoreWithoutLoss($unsupportedDef));
        $restored = $manager->restoreTable('unsupported_table', $unsupportedDef);
        $this->assertFalse($restored);
        $this->assertFalse(Schema::hasTable('unsupported_table'));
        $this->assertStringContainsString('lossy features', $manager->getLastError() ?? '');
    }

    public function test_legacy_bridge_generates_no_real_data_in_seeders(): void
    {
        $directory = sys_get_temp_dir().'/lifecycle-safe-seeder-'.bin2hex(random_bytes(6));
        mkdir($directory, 0700, true);

        try {
            $method = new ReflectionMethod(LegacyBridge::class, 'generateSeeder');
            $method->invoke(new LegacyBridge, 'users', $directory, null);

            $generated = file_get_contents($directory.'/UsersTableSeeder.php');
            $this->assertIsString($generated);
            $this->assertStringContainsString('Live database rows are intentionally not exported', $generated);
            $this->assertStringNotContainsString('->insert(', $generated);
        } finally {
            @unlink($directory.'/UsersTableSeeder.php');
            @rmdir($directory);
        }
    }
}
