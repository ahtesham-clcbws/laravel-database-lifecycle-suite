<?php

namespace Tests;

use PHPUnit\Framework\TestCase;

class EvidenceDiffValidatorTest extends TestCase
{
    public function test_evidence_only_diff_validator_rejects_executable_code_changes(): void
    {
        $script = __DIR__.'/../scripts/validate-evidence-diff.sh';
        $this->assertFileExists($script);

        // Commit 1690636 introduced changes to tests/VerificationRunnerResilienceTest.php
        // Commit 4f7f707 modified that test file as well
        $cmd = 'bash '.escapeshellarg($script).' 1690636 4f7f707 2>&1';
        exec($cmd, $output, $exitCode);

        $this->assertNotEquals(0, $exitCode, 'Expected diff validator to reject non-documentation changes');
        $this->assertStringContainsString('Disallowed executable or configuration changes detected', implode("\n", $output));
    }

    public function test_evidence_only_diff_validator_accepts_pure_evidence_commits(): void
    {
        $script = __DIR__.'/../scripts/validate-evidence-diff.sh';
        $this->assertFileExists($script);

        // b765d6f to 26f922b contains only reports and documentation
        $cmd = 'bash '.escapeshellarg($script).' b765d6f 26f922b 2>&1';
        exec($cmd, $output, $exitCode);

        $this->assertEquals(0, $exitCode, 'Expected diff validator to accept evidence-only changes: '.implode("\n", $output));
        $this->assertStringContainsString('verified evidence-only', implode("\n", $output));
    }
}
