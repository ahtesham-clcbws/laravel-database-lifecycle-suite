<?php

declare(strict_types=1);

namespace Clcbws\DatabaseLifecycleSuite\Tests;

use Clcbws\DatabaseLifecycleSuite\LegacyBridge;
use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;

class LegacyBridgeTest extends TestCase
{
    protected LegacyBridge $bridge;
    protected string $outputPath;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bridge = new LegacyBridge();
        $this->outputPath = __DIR__ . '/bridge_output';

        Schema::create('users', function (Blueprint $table) {
            $table->id();
        });

        Schema::create('posts', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('user_id');
            $table->foreign('user_id')->references('id')->on('users');
        });
    }

    protected function tearDown(): void
    {
        $this->deleteDirectory($this->outputPath);
        parent::tearDown();
    }

    public function test_it_generates_migration_with_relationships()
    {
        $results = $this->bridge->reverseEngineer(['users', 'posts'], $this->outputPath);

        $this->assertArrayHasKey('users', $results);
        $this->assertArrayHasKey('posts', $results);
        
        $migrationFiles = glob($this->outputPath . '/migrations/*.php');
        $this->assertCount(2, $migrationFiles);

        // Find the posts migration specifically
        $postMigrationFile = \collect($migrationFiles)->first(fn($f) => str_contains($f, 'create_posts_table'));
        $this->assertNotNull($postMigrationFile);
        
        $content = file_get_contents($postMigrationFile);
        $this->assertStringContainsString("->references('id')->on('users')", $content);
    }

    public function test_it_generates_model_with_relationships()
    {
        $this->bridge->reverseEngineer(['users', 'posts'], $this->outputPath);

        $this->assertFileExists($this->outputPath . '/models/User.php');
        $this->assertFileExists($this->outputPath . '/models/Post.php');

        $userModel = file_get_contents($this->outputPath . '/models/User.php');
        $this->assertStringContainsString('public function posts()', $userModel);
        $this->assertStringContainsString('return $this->hasMany(Post::class', $userModel);

        $postModel = file_get_contents($this->outputPath . '/models/Post.php');
        $this->assertStringContainsString('public function user()', $postModel);
        $this->assertStringContainsString('return $this->belongsTo(User::class', $postModel);
    }

    protected function deleteDirectory(string $dir): void
    {
        if (!is_dir($dir)) return;
        $files = array_diff(scandir($dir), ['.', '..']);
        foreach ($files as $file) {
            (is_dir("$dir/$file")) ? $this->deleteDirectory("$dir/$file") : unlink("$dir/$file");
        }
        rmdir($dir);
    }
}
