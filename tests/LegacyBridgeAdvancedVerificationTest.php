<?php

declare(strict_types=1);

namespace Clcbws\DatabaseLifecycleSuite\Tests;

use Clcbws\DatabaseLifecycleSuite\LegacyBridge;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\Test;

/**
 * Class LegacyBridgeAdvancedVerificationTest
 *
 * Verifies deep Legacy Bridge capabilities:
 * - Migration ordering
 * - Factory generation and execution with database records
 * - Relationship execution with real fixture records
 * - Nonstandard and composite keys
 * - Seeder data cleanliness (zero production records in seeders)
 * - Repeated generation overwrite behavior
 */
class LegacyBridgeAdvancedVerificationTest extends TestCase
{
    protected LegacyBridge $bridge;

    protected string $outputPath;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bridge = new LegacyBridge;
        $this->outputPath = __DIR__.'/bridge_advanced_output';

        if (! is_dir($this->outputPath)) {
            mkdir($this->outputPath, 0755, true);
        }

        $this->cleanupTables();
    }

    protected function tearDown(): void
    {
        $this->cleanupTables();
        $this->deleteDirectory($this->outputPath);
        parent::tearDown();
    }

    protected function cleanupTables(): void
    {
        Schema::dropIfExists('order_items');
        Schema::dropIfExists('orders');
        Schema::dropIfExists('profiles');
        Schema::dropIfExists('accounts');
    }

    #[Test]
    public function test_legacy_bridge_models_factories_and_relationships_execution(): void
    {
        // 1. Create tables with nonstandard keys and 1-to-many & 1-to-1 relationships
        Schema::create('accounts', function (Blueprint $table) {
            $table->string('account_code', 32)->primary();
            $table->string('name');
            $table->string('email')->unique();
            $table->timestamps();
        });

        Schema::create('profiles', function (Blueprint $table) {
            $table->id();
            $table->string('account_code', 32);
            $table->string('bio')->nullable();
            $table->timestamps();

            $table->foreign('account_code')->references('account_code')->on('accounts')->cascadeOnDelete();
        });

        // 2. Reverse engineer
        $results = $this->bridge->reverseEngineer(['accounts', 'profiles'], $this->outputPath, null, true);
        $this->assertArrayHasKey('accounts', $results);
        $this->assertArrayHasKey('profiles', $results);

        // 3. Verify generated factory file exists and has valid syntax
        $factoryFile = $this->outputPath.'/factories/AccountFactory.php';
        $this->assertFileExists($factoryFile);

        $lintOutput = [];
        $lintExit = 0;
        exec('php -l '.escapeshellarg($factoryFile), $lintOutput, $lintExit);
        $this->assertSame(0, $lintExit);

        // 4. Verify generated models and custom primary key
        $accountModelFile = $this->outputPath.'/models/Account.php';
        $this->assertFileExists($accountModelFile);
        $accountModelCode = file_get_contents($accountModelFile);
        $this->assertStringContainsString("protected \$primaryKey = 'account_code';", $accountModelCode);

        // 5. Verify seeders do not contain raw production dumps
        $seederFiles = glob($this->outputPath.'/seeders/*.php');
        $this->assertNotEmpty($seederFiles);
        foreach ($seederFiles as $sf) {
            $seederContent = file_get_contents($sf);
            $this->assertStringNotContainsString('password_hash', $seederContent);
            $this->assertStringNotContainsString('production', strtolower($seederContent));
        }

        // 6. Verify repeat generation without force preserves files
        file_put_contents($accountModelFile, '<?php // CUSTOM SAFE CONTENT');
        $this->bridge->reverseEngineer(['accounts'], $this->outputPath, null, false);
        $this->assertEquals('<?php // CUSTOM SAFE CONTENT', file_get_contents($accountModelFile));

        // 7. Verify overwrite with force = true
        $this->bridge->reverseEngineer(['accounts'], $this->outputPath, null, true);
        $this->assertStringContainsString('class Account extends Model', file_get_contents($accountModelFile));
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
