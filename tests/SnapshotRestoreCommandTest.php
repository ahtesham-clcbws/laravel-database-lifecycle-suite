<?php

declare(strict_types=1);

namespace Clcbws\DatabaseLifecycleSuite\Tests;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class SnapshotRestoreCommandTest extends TestCase
{
    protected string $snapshotPath;

    protected function setUp(): void
    {
        parent::setUp();
        $this->snapshotPath = \base_path('database/snapshots');

        if (! is_dir($this->snapshotPath)) {
            @mkdir($this->snapshotPath, 0755, true);
        }
    }

    protected function tearDown(): void
    {
        Schema::dropIfExists('restored_table');
        Schema::dropIfExists('unsupported_table');

        if (is_dir($this->snapshotPath)) {
            $files = glob($this->snapshotPath.'/*.json');
            foreach ($files as $file) {
                @unlink($file);
            }
            @rmdir($this->snapshotPath);
        }

        parent::tearDown();
    }

    public function test_it_rejects_path_traversal_snapshot_name(): void
    {
        $this->artisan('db:snapshot-restore', ['name' => '../../secret'])
            ->expectsOutputToContain('Invalid snapshot name')
            ->assertExitCode(1);
    }

    public function test_it_rejects_non_existent_snapshot(): void
    {
        $this->artisan('db:snapshot-restore', ['name' => 'non_existent_snapshot'])
            ->expectsOutputToContain('Failed to load snapshot')
            ->assertExitCode(1);
    }

    public function test_preflight_rejection_leaves_database_untouched(): void
    {
        $badSnapshot = [
            'version' => '1.0',
            'tables' => [
                'unsupported_table' => [
                    'columns' => [
                        ['name' => 'geo', 'type_name' => 'geography_unsupported', 'nullable' => true, 'default' => null],
                    ],
                    'indexes' => [],
                    'foreign_keys' => [],
                ],
            ],
        ];

        file_put_contents($this->snapshotPath.'/bad_types.json', json_encode($badSnapshot));

        $this->artisan('db:snapshot-restore', ['name' => 'bad_types'])
            ->expectsOutputToContain('Preflight check failed')
            ->assertExitCode(1);

        $this->assertFalse(Schema::hasTable('unsupported_table'));
    }

    public function test_dry_run_mode_does_not_modify_database(): void
    {
        $validSnapshot = [
            'version' => '1.0',
            'tables' => [
                'restored_table' => [
                    'columns' => [
                        ['name' => 'id', 'type_name' => 'bigint', 'nullable' => false, 'default' => null, 'auto_increment' => true],
                        ['name' => 'title', 'type_name' => 'varchar', 'nullable' => false, 'default' => null],
                    ],
                    'indexes' => [],
                    'foreign_keys' => [],
                ],
            ],
        ];

        file_put_contents($this->snapshotPath.'/dry_run_snap.json', json_encode($validSnapshot));

        $this->artisan('db:snapshot-restore', ['name' => 'dry_run_snap', '--dry-run' => true])
            ->expectsOutputToContain('[DRY RUN]')
            ->expectsOutputToContain('Dry run completed. No database changes were made.')
            ->assertExitCode(0);

        $this->assertFalse(Schema::hasTable('restored_table'));
    }

    public function test_successful_restoration_creates_table_and_indexes(): void
    {
        $validSnapshot = [
            'version' => '1.0',
            'tables' => [
                'restored_table' => [
                    'columns' => [
                        ['name' => 'id', 'type_name' => 'bigint', 'nullable' => false, 'default' => null, 'auto_increment' => true],
                        ['name' => 'title', 'type_name' => 'varchar', 'nullable' => false, 'default' => null],
                        ['name' => 'slug', 'type_name' => 'varchar', 'nullable' => false, 'default' => null],
                    ],
                    'indexes' => [
                        ['name' => 'restored_slug_unique', 'columns' => ['slug'], 'unique' => true, 'primary' => false],
                    ],
                    'foreign_keys' => [],
                ],
            ],
        ];

        file_put_contents($this->snapshotPath.'/valid_snap.json', json_encode($validSnapshot));

        $this->artisan('db:snapshot-restore', ['name' => 'valid_snap'])
            ->expectsConfirmation('Are you sure you want to restore schema snapshot [valid_snap]?', 'yes')
            ->expectsOutputToContain('Schema structural restoration complete.')
            ->assertExitCode(0);

        $this->assertTrue(Schema::hasTable('restored_table'));
        $this->assertTrue(Schema::hasColumn('restored_table', 'title'));
        $this->assertTrue(Schema::hasColumn('restored_table', 'slug'));
    }

    public function test_recreate_mode_drops_and_recreates_table(): void
    {
        Schema::create('restored_table', function (Blueprint $table) {
            $table->id();
            $table->string('old_col');
        });

        $snapshot = [
            'version' => '1.0',
            'tables' => [
                'restored_table' => [
                    'columns' => [
                        ['name' => 'id', 'type_name' => 'bigint', 'nullable' => false, 'default' => null, 'auto_increment' => true],
                        ['name' => 'new_col', 'type_name' => 'varchar', 'nullable' => false, 'default' => null],
                    ],
                    'indexes' => [],
                    'foreign_keys' => [],
                ],
            ],
        ];

        file_put_contents($this->snapshotPath.'/recreate_snap.json', json_encode($snapshot));

        $this->artisan('db:snapshot-restore', ['name' => 'recreate_snap', '--recreate' => true, '--force' => true])
            ->expectsOutputToContain('Dropping existing table [restored_table]')
            ->expectsOutputToContain('Schema structural restoration complete.')
            ->assertExitCode(0);

        $this->assertTrue(Schema::hasTable('restored_table'));
        $this->assertTrue(Schema::hasColumn('restored_table', 'new_col'));
        $this->assertFalse(Schema::hasColumn('restored_table', 'old_col'));
    }
}
