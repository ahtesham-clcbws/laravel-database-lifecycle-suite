<?php

declare(strict_types=1);

namespace Clcbws\DatabaseLifecycleSuite\Tests;

use Clcbws\DatabaseLifecycleSuite\SoftDeleteAuditor;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class SoftDeleteAuditorTest extends TestCase
{
    protected SoftDeleteAuditor $auditor;

    protected string $modelsDir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->auditor = new SoftDeleteAuditor;
        $this->modelsDir = sys_get_temp_dir().'/test_soft_models_'.bin2hex(random_bytes(4));
        mkdir($this->modelsDir, 0755, true);

        // Missing deleted_at column
        Schema::create('soft_missing_cols', function (Blueprint $table) {
            $table->id();
            $table->string('name');
        });

        // Table has deleted_at column, but model lacks trait
        Schema::create('missing_trait_tables', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->softDeletes();
        });

        $model1 = <<<'PHP'
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

if (!class_exists(SoftMissingCol::class)) {
    class SoftMissingCol extends Model
    {
        use SoftDeletes;
        protected $table = 'soft_missing_cols';
    }
}
PHP;

        $model2 = <<<'PHP'
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

if (!class_exists(MissingTraitTable::class)) {
    class MissingTraitTable extends Model
    {
        protected $table = 'missing_trait_tables';
    }
}
PHP;

        file_put_contents($this->modelsDir.'/SoftMissingCol.php', $model1);
        file_put_contents($this->modelsDir.'/MissingTraitTable.php', $model2);

        require_once $this->modelsDir.'/SoftMissingCol.php';
        require_once $this->modelsDir.'/MissingTraitTable.php';
    }

    protected function tearDown(): void
    {
        Schema::dropIfExists('soft_missing_cols');
        Schema::dropIfExists('missing_trait_tables');

        if (is_dir($this->modelsDir)) {
            $files = glob($this->modelsDir.'/*.php');
            foreach ($files as $file) {
                @unlink($file);
            }
            @rmdir($this->modelsDir);
        }

        parent::tearDown();
    }

    public function test_it_detects_soft_delete_inconsistencies(): void
    {
        $connection = (string) config('database.default');
        $inconsistencies = $this->auditor->audit($this->modelsDir, $connection);

        $this->assertCount(2, $inconsistencies);

        $issues = \collect($inconsistencies)->pluck('issue')->toArray();
        $this->assertTrue(\collect($issues)->contains(fn ($i) => str_contains($i, 'missing [deleted_at] column')));
        $this->assertTrue(\collect($issues)->contains(fn ($i) => str_contains($i, 'missing SoftDeletes trait')));
    }

    public function test_soft_delete_command_with_strict_fails_on_inconsistencies(): void
    {
        $connection = (string) config('database.default');
        $this->artisan('db:soft-delete-audit', [
            '--path' => $this->modelsDir,
            '--database' => $connection,
            '--strict' => true,
        ])
            ->expectsOutputToContain('inconsistencies')
            ->assertExitCode(1);
    }
}
