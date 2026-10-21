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
