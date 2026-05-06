<?php

declare(strict_types=1);

namespace Clcbws\DatabaseLifecycleSuite\Tests;

use Clcbws\DatabaseLifecycleSuite\SchemaSnapshotManager;
use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;

class SchemaSnapshotManagerTest extends TestCase
{
    protected SchemaSnapshotManager $manager;
    protected string $snapshotPath;

    protected function setUp(): void
    {
        parent::setUp();
        $this->manager = new SchemaSnapshotManager();
        $this->snapshotPath = __DIR__ . '/snapshots';
        
        if (!is_dir($this->snapshotPath)) {
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
        
        if (is_dir($this->snapshotPath)) {
            $files = glob($this->snapshotPath . '/*.json');
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
}
