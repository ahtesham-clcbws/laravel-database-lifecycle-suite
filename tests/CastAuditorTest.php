<?php

declare(strict_types=1);

namespace Clcbws\DatabaseLifecycleSuite\Tests;

use Clcbws\DatabaseLifecycleSuite\CastAuditor;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CastAuditorTest extends TestCase
{
    protected CastAuditor $auditor;

    protected string $modelsDir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->auditor = new CastAuditor;
        $this->modelsDir = sys_get_temp_dir().'/test_models_'.bin2hex(random_bytes(4));
        mkdir($this->modelsDir, 0755, true);

        Schema::create('sample_cast_users', function (Blueprint $table) {
            $table->id();
            $table->text('profile_data');
            $table->string('status_text');
        });
    }

    protected function tearDown(): void
    {
        Schema::dropIfExists('sample_cast_users');

        if (is_dir($this->modelsDir)) {
            $files = glob($this->modelsDir.'/*.php');
            foreach ($files as $file) {
                @unlink($file);
            }
            @rmdir($this->modelsDir);
        }

        parent::tearDown();
    }

    public function test_it_detects_cast_mismatches_against_database_types(): void
    {
        $code = <<<'PHP'
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SampleCastUser extends Model
{
    protected $table = 'sample_cast_users';

    protected $casts = [
        'status_text' => 'integer', // DB is string/text -> mismatch!
        'profile_data' => 'array',  // DB is text -> compatible (json/text)
    ];
}
PHP;

        file_put_contents($this->modelsDir.'/SampleCastUser.php', $code);
        require_once $this->modelsDir.'/SampleCastUser.php';

        $mismatches = $this->auditor->audit($this->modelsDir, 'testing');

        $this->assertNotEmpty($mismatches);
        $this->assertEquals('status_text', $mismatches[0]['column']);
        $this->assertEquals('integer', $mismatches[0]['cast']);
    }

    public function test_cast_audit_command_with_strict_fails_on_mismatches(): void
    {
        $code = <<<'PHP'
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SampleStrictCastUser extends Model
{
    protected $table = 'sample_cast_users';

    protected $casts = [
        'status_text' => 'boolean',
    ];
}
PHP;

        file_put_contents($this->modelsDir.'/SampleStrictCastUser.php', $code);
        require_once $this->modelsDir.'/SampleStrictCastUser.php';

        $this->artisan('db:cast-audit', [
            '--path' => $this->modelsDir,
            '--database' => 'testing',
            '--strict' => true,
        ])
            ->expectsOutputToContain('cast inconsistencies')
            ->assertExitCode(1);
    }
}
