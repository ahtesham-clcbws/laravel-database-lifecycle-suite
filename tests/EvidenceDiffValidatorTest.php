<?php

namespace Tests;

use PHPUnit\Framework\TestCase;

class EvidenceDiffValidatorTest extends TestCase
{
    private string $tempRepo;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tempRepo = sys_get_temp_dir().'/git-test-repo-'.uniqid();
        mkdir($this->tempRepo);
        exec("cd {$this->tempRepo} && git init && git config user.name 'Test' && git config user.email 'test@example.com'");
    }

    protected function tearDown(): void
    {
        exec('rm -rf '.escapeshellarg($this->tempRepo));
        parent::tearDown();
    }

    public function test_evidence_only_diff_validator_rejects_executable_code_changes(): void
    {
        $script = __DIR__.'/../scripts/validate-evidence-diff.sh';
        $this->assertFileExists($script);

        // Commit initial code file
        file_put_contents($this->tempRepo.'/file.php', '<?php echo "hello";');
        exec("cd {$this->tempRepo} && git add file.php && git commit -m 'initial commit'");
        $sourceSha = trim(shell_exec("cd {$this->tempRepo} && git rev-parse HEAD"));

        // Commit executable code modification
        file_put_contents($this->tempRepo.'/file.php', '<?php echo "changed";');
        exec("cd {$this->tempRepo} && git add file.php && git commit -m 'executable change'");
        $evidenceSha = trim(shell_exec("cd {$this->tempRepo} && git rev-parse HEAD"));

        $cmd = "cd {$this->tempRepo} && bash ".escapeshellarg($script)." {$sourceSha} {$evidenceSha} 2>&1";
        exec($cmd, $output, $exitCode);

        $this->assertNotEquals(0, $exitCode, 'Expected diff validator to reject non-documentation changes');
        $this->assertStringContainsString('Disallowed executable or configuration changes detected', implode("\n", $output));
    }

    public function test_evidence_only_diff_validator_accepts_pure_evidence_commits(): void
    {
        $script = __DIR__.'/../scripts/validate-evidence-diff.sh';
        $this->assertFileExists($script);

        // Commit initial code file
        file_put_contents($this->tempRepo.'/file.php', '<?php echo "hello";');
        exec("cd {$this->tempRepo} && git add file.php && git commit -m 'initial commit'");
        $sourceSha = trim(shell_exec("cd {$this->tempRepo} && git rev-parse HEAD"));

        // Commit pure markdown and report files
        file_put_contents($this->tempRepo.'/README.md', '# Documentation');
        mkdir($this->tempRepo.'/reports');
        file_put_contents($this->tempRepo.'/reports/test.log', 'OK');
        exec("cd {$this->tempRepo} && git add README.md reports/ && git commit -m 'evidence commit'");
        $evidenceSha = trim(shell_exec("cd {$this->tempRepo} && git rev-parse HEAD"));

        $cmd = "cd {$this->tempRepo} && bash ".escapeshellarg($script)." {$sourceSha} {$evidenceSha} 2>&1";
        exec($cmd, $output, $exitCode);

        $this->assertEquals(0, $exitCode, 'Expected diff validator to accept evidence-only changes: '.implode("\n", $output));
        $this->assertStringContainsString('verified evidence-only', implode("\n", $output));
    }
}
