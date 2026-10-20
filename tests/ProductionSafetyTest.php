<?php

declare(strict_types=1);

namespace Clcbws\DatabaseLifecycleSuite\Tests;

use Clcbws\DatabaseLifecycleSuite\SchemaSnapshotManager;
use Clcbws\DatabaseLifecycleSuite\LegacyBridge;
use ReflectionMethod;

class ProductionSafetyTest extends TestCase
{
    public function test_snapshot_preflight_rejects_indexes_and_foreign_keys(): void
    {
        $manager = new SchemaSnapshotManager();

        $definition = [
            'columns' => [['name' => 'notes', 'type_name' => 'text', 'nullable' => true, 'default' => null]],
            'indexes' => [['name' => 'notes_index']],
            'foreign_keys' => [],
        ];

        $this->assertFalse($manager->canRestoreWithoutLoss($definition));

        $definition['indexes'] = [];
        $definition['foreign_keys'] = [['name' => 'fk_notes']];
        $this->assertFalse($manager->canRestoreWithoutLoss($definition));
    }

    public function test_snapshot_preflight_rejects_unsupported_columns(): void
    {
        $manager = new SchemaSnapshotManager();
        $this->assertFalse($manager->canRestoreWithoutLoss([
            'columns' => [['name' => 'id', 'type_name' => 'bigint', 'nullable' => false, 'default' => null]],
            'indexes' => [],
            'foreign_keys' => [],
        ]));
        $this->assertTrue($manager->canRestoreWithoutLoss([
            'columns' => [['name' => 'notes', 'type_name' => 'text', 'nullable' => true, 'default' => null]],
            'indexes' => [],
            'foreign_keys' => [],
        ]));
    }

    public function test_legacy_bridge_generates_no_real_data_in_seeders(): void
    {
        $directory = sys_get_temp_dir() . '/lifecycle-safe-seeder-' . bin2hex(random_bytes(6));
        mkdir($directory, 0700, true);

        try {
            $method = new ReflectionMethod(LegacyBridge::class, 'generateSeeder');
            $method->invoke(new LegacyBridge(), 'users', $directory, null);

            $generated = file_get_contents($directory . '/UsersTableSeeder.php');
            $this->assertIsString($generated);
            $this->assertStringContainsString('Live database rows are intentionally not exported', $generated);
            $this->assertStringNotContainsString('->insert(', $generated);
        } finally {
            @unlink($directory . '/UsersTableSeeder.php');
            @rmdir($directory);
        }
    }
}
