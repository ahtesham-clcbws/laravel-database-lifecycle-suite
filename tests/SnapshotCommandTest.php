<?php

declare(strict_types=1);

namespace Clcbws\DatabaseLifecycleSuite\Tests;

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;

class SnapshotCommandTest extends TestCase
{
    protected string $snapshotPath;

    protected function setUp(): void
    {
        parent::setUp();
        $this->snapshotPath = \base_path('database/snapshots');
        
        if (!is_dir($this->snapshotPath)) {
            @mkdir($this->snapshotPath, 0755, true);
        }

        Schema::create('users', function (Blueprint $table) {
            $table->id();
        });
    }

    protected function tearDown(): void
    {
        if (is_dir($this->snapshotPath)) {
            $files = glob($this->snapshotPath . '/*.json');
            foreach ($files as $file) {
                @unlink($file);
            }
            @rmdir($this->snapshotPath);
        }
        parent::tearDown();
    }

    public function test_it_creates_a_snapshot()
    {
        $this->artisan('db:snapshot', ['name' => 'test_cli'])
            ->expectsOutputToContain('Snapshot saved to')
            ->assertExitCode(0);

        $this->assertFileExists($this->snapshotPath . '/test_cli.json');
    }

    public function test_it_prevents_unintended_overwrites()
    {
        // Create first one manually
        @mkdir($this->snapshotPath, 0755, true);
        file_put_contents($this->snapshotPath . '/exists.json', '{}');

        $this->artisan('db:snapshot', ['name' => 'exists'])
            ->expectsConfirmation('Snapshot [exists] already exists. Overwrite?', 'no')
            ->expectsOutputToContain('Snapshot aborted') // Updated to match actual code
            ->assertExitCode(0);
    }
}
