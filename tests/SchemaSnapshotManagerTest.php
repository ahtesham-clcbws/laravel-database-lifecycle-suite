<?php

declare(strict_types=1);

namespace Clcbws\DatabaseLifecycleSuite\Tests;

use Clcbws\DatabaseLifecycleSuite\SchemaSnapshotManager;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class SchemaSnapshotManagerTest extends TestCase
{
    protected SchemaSnapshotManager $manager;

    protected string $snapshotPath;

    protected function setUp(): void
    {
        parent::setUp();
        $this->manager = new SchemaSnapshotManager;
        $this->snapshotPath = __DIR__.'/snapshots';

        if (! is_dir($this->snapshotPath)) {
            mkdir($this->snapshotPath, 0755, true);
        }

        Schema::create('users', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('email')->unique();
            $table->timestamps();
        });
    }

    protected function tearDown(): void
    {
        Schema::dropIfExists('users');
        Schema::dropIfExists('new_table');
        Schema::dropIfExists('accounts');
        Schema::dropIfExists('restored_accounts');

        if (is_dir($this->snapshotPath)) {
            $files = glob($this->snapshotPath.'/*.json');
            foreach ($files as $file) {
                @unlink($file);
            }
            @rmdir($this->snapshotPath);
        }

        parent::tearDown();
    }

    public function test_it_can_capture_a_snapshot()
    {
        $path = $this->manager->capture('test_snapshot', $this->snapshotPath);

        $this->assertFileExists($path);
        $data = json_decode(file_get_contents($path), true);

        $this->assertArrayHasKey('users', $data['tables']);
        $this->assertEquals('id', $data['tables']['users']['columns'][0]['name']);
    }

    public function test_it_can_compare_two_snapshots()
    {
        // Snapshot 1 (Original)
        $this->manager->capture('v1', $this->snapshotPath);
        $v1 = $this->manager->load('v1', $this->snapshotPath);

        // Modify Schema
        Schema::create('new_table', function (Blueprint $table) {
            $table->id();
        });

        // Snapshot 2 (Modified)
        $this->manager->capture('v2', $this->snapshotPath);
        $v2 = $this->manager->load('v2', $this->snapshotPath);

        $diff = $this->manager->compare($v1, $v2);

        $this->assertContains('new_table', $diff['tables_added']);
    }

    public function test_it_can_detect_column_changes()
    {
        $this->manager->capture('base', $this->snapshotPath);
        $base = $this->manager->load('base', $this->snapshotPath);

        // Simulate a change in a definition manually
        $new = $base;
        $new['tables']['users']['columns'][1]['type_name'] = 'text';

        $diff = $this->manager->compare($base, $new);

        $this->assertArrayHasKey('users', $diff['table_changes']);
        $this->assertArrayHasKey('column_changes', $diff['table_changes']['users']);
        $this->assertArrayHasKey('name', $diff['table_changes']['users']['column_changes']);

        // Correct key is 'type'
        $this->assertEquals('text', $diff['table_changes']['users']['column_changes']['name']['type']['to']);
    }

    public function test_round_trip_schema_restoration_preserves_non_incrementing_pk_and_columns(): void
    {
        Schema::create('accounts', function (Blueprint $table) {
            $table->string('code', 32)->primary();
            $table->decimal('balance', 10, 2)->default(0.00);
            $table->text('notes')->nullable();
        });

        $this->manager->capture('accounts_snap', $this->snapshotPath);
        $snapshot = $this->manager->load('accounts_snap', $this->snapshotPath);

        $accountsDef = $snapshot['tables']['accounts'];
        $this->assertNotEmpty($accountsDef);

        // String PK must NOT be marked auto_increment
        $colMap = [];
        foreach ($accountsDef['columns'] as $c) {
            $colMap[$c['name']] = $c;
        }
        $this->assertFalse(! empty($colMap['code']['auto_increment']));

        // Restore into 'restored_accounts'
        $restored = $this->manager->restoreTable('restored_accounts', $accountsDef);
        $this->assertTrue($restored, 'Failed restoring table: '.$this->manager->getLastError());

        $this->assertTrue(Schema::hasTable('restored_accounts'));
        $this->assertTrue(Schema::hasColumn('restored_accounts', 'code'));
        $this->assertTrue(Schema::hasColumn('restored_accounts', 'balance'));
        $this->assertTrue(Schema::hasColumn('restored_accounts', 'notes'));
    }

    public function test_restore_table_preserves_explicit_non_incrementing_integer_primary_key(): void
    {
        $definition = [
            'columns' => [
                ['name' => 'external_id', 'type_name' => 'integer', 'nullable' => false, 'default' => null, 'auto_increment' => false],
                ['name' => 'name', 'type_name' => 'varchar', 'nullable' => false, 'default' => null],
            ],
            'indexes' => [
                ['name' => 'pk', 'columns' => ['external_id'], 'primary' => true, 'unique' => true],
            ],
            'foreign_keys' => [],
        ];

        $restored = $this->manager->restoreTable('non_inc_table', $definition);
        $this->assertTrue($restored);
        $this->assertTrue(Schema::hasTable('non_inc_table'));
        $this->assertTrue(Schema::hasColumn('non_inc_table', 'external_id'));

        Schema::dropIfExists('non_inc_table');
    }
}
