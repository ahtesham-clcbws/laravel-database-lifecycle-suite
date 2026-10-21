<?php

declare(strict_types=1);

namespace Clcbws\DatabaseLifecycleSuite\Tests;

use Clcbws\DatabaseLifecycleSuite\LegacyBridge;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class LegacyBridgeTest extends TestCase
{
    protected LegacyBridge $bridge;

    protected string $outputPath;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bridge = new LegacyBridge;
        $this->outputPath = __DIR__.'/bridge_output';

        Schema::create('users', function (Blueprint $table) {
            $table->id();
            $table->string('email');
        });

        Schema::create('posts', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('author_id');
            $table->unsignedBigInteger('reviewer_id');
            $table->decimal('price', 10, 2)->default(0.00);
            $table->foreign('author_id')->references('id')->on('users')->onDelete('cascade');
            $table->foreign('reviewer_id')->references('id')->on('users')->onDelete('restrict');
        });

        Schema::create('custom_keys', function (Blueprint $table) {
            $table->string('code')->primary();
            $table->string('label');
        });
    }

    protected function tearDown(): void
    {
        Schema::dropIfExists('posts');
        Schema::dropIfExists('users');
        Schema::dropIfExists('custom_keys');

        $this->deleteDirectory($this->outputPath);
        parent::tearDown();
    }

    public function test_it_generates_migration_with_relationships_and_foreign_actions(): void
    {
        $results = $this->bridge->reverseEngineer(['users', 'posts'], $this->outputPath);

        $this->assertArrayHasKey('users', $results);
        $this->assertArrayHasKey('posts', $results);

        $migrationFiles = glob($this->outputPath.'/migrations/*.php');
        $this->assertCount(2, $migrationFiles);

        $postMigrationFile = \collect($migrationFiles)->first(fn ($f) => str_contains($f, 'create_posts_table'));
        $this->assertNotNull($postMigrationFile);

        $content = file_get_contents($postMigrationFile);
        $this->assertStringContainsString("->references('id')->on('users')->onDelete('cascade')", $content);
        $this->assertStringContainsString("->references('id')->on('users')->onDelete('restrict')", $content);
        $this->assertStringContainsString("decimal('price')", $content);
    }

    public function test_it_generates_model_with_disambiguated_relationships(): void
    {
        $this->bridge->reverseEngineer(['users', 'posts'], $this->outputPath);

        $this->assertFileExists($this->outputPath.'/models/User.php');
        $this->assertFileExists($this->outputPath.'/models/Post.php');

        $postModel = file_get_contents($this->outputPath.'/models/Post.php');
        // Both author_id and reviewer_id reference users; method names should not collide!
        $this->assertStringContainsString('public function author()', $postModel);
        $this->assertStringContainsString('public function reviewer()', $postModel);
        $this->assertStringContainsString('return $this->belongsTo(User::class, \'author_id\');', $postModel);
        $this->assertStringContainsString('return $this->belongsTo(User::class, \'reviewer_id\');', $postModel);
    }

    public function test_it_respects_custom_primary_key_and_connection(): void
    {
        $this->bridge->reverseEngineer(['custom_keys'], $this->outputPath, 'testing');

        $this->assertFileExists($this->outputPath.'/models/CustomKey.php');
        $model = file_get_contents($this->outputPath.'/models/CustomKey.php');

        $this->assertStringContainsString("protected \$connection = 'testing';", $model);
        $this->assertStringContainsString("protected \$primaryKey = 'code';", $model);
    }

    public function test_legacy_bridge_command_executes_successfully(): void
    {
        $this->artisan('db:legacy-bridge', ['--tables' => 'users,posts'])
            ->expectsOutputToContain('Initializing Legacy Bridge')
            ->expectsOutputToContain('Legacy Bridge completed successfully.')
            ->assertExitCode(0);
    }

    public function test_it_maps_decimal_precision_scale_and_unsigned(): void
    {
        $method = new \ReflectionMethod(LegacyBridge::class, 'mapColumnToMigration');

        $decimalCol = [
            'name' => 'balance',
            'type_name' => 'decimal',
            'precision' => 14,
            'scale' => 4,
            'nullable' => false,
            'default' => null,
            'auto_increment' => false,
        ];
        $result = $method->invoke($this->bridge, $decimalCol, false);
        $this->assertEquals("\$table->decimal('balance', 14, 4)", $result['statement']);

        $unsignedCol = [
            'name' => 'views',
            'type_name' => 'integer',
            'type' => 'integer unsigned',
            'unsigned' => true,
            'nullable' => true,
            'default' => 0,
            'auto_increment' => false,
        ];
        $resultUnsigned = $method->invoke($this->bridge, $unsignedCol, false);
        $this->assertStringContainsString('->unsigned()', $resultUnsigned['statement']);
        $this->assertStringContainsString('->nullable()', $resultUnsigned['statement']);
        $this->assertStringContainsString('->default(0)', $resultUnsigned['statement']);
    }

    protected function deleteDirectory(string $dir): void
    {
        if (! is_dir($dir)) {
            return;
        }
        $files = array_diff(scandir($dir), ['.', '..']);
        foreach ($files as $file) {
            (is_dir("$dir/$file")) ? $this->deleteDirectory("$dir/$file") : unlink("$dir/$file");
        }
        rmdir($dir);
    }
}
