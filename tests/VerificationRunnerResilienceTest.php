<?php

namespace Tests;

use PHPUnit\Framework\TestCase;

class VerificationRunnerResilienceTest extends TestCase
{
    private string $rootDir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->rootDir = realpath(__DIR__.'/..');
    }

    public function test_verify_release_fails_on_invalid_database_option(): void
    {
        $output = [];
        $exitCode = 0;
        exec("bash {$this->rootDir}/scripts/verify-release.sh --database=invalid 2>&1", $output, $exitCode);
        $this->assertNotEquals(0, $exitCode);
        $this->assertStringContainsString('Invalid database target', implode("\n", $output));
    }

    public function test_verify_release_fails_on_invalid_laravel_option(): void
    {
        $output = [];
        $exitCode = 0;
        exec("bash {$this->rootDir}/scripts/verify-release.sh --laravel=invalid 2>&1", $output, $exitCode);
        $this->assertNotEquals(0, $exitCode);
        $this->assertStringContainsString('Invalid Laravel target', implode("\n", $output));
    }

    public function test_run_app_integration_fails_on_invalid_option(): void
    {
        $output = [];
        $exitCode = 0;
        exec("bash {$this->rootDir}/tests/integration-app/scripts/run-app-integration.sh --laravel=99 2>&1", $output, $exitCode);
        $this->assertNotEquals(0, $exitCode);
        $this->assertStringContainsString('Unknown argument', implode("\n", $output));
    }
}
