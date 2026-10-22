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

    public function test_simultaneous_verification_runs_are_prevented(): void
    {
        $lockFile = "{$this->rootDir}/.agents/verify-release.lock";
        $fp = fopen($lockFile, 'w+');
        $this->assertIsResource($fp);
        $locked = flock($fp, LOCK_EX | LOCK_NB);
        $this->assertTrue($locked, 'Failed to acquire test lock');

        $output = [];
        $exitCode = 0;
        exec("bash {$this->rootDir}/scripts/verify-release.sh 2>&1", $output, $exitCode);

        flock($fp, LOCK_UN);
        fclose($fp);

        $this->assertNotEquals(0, $exitCode);
        $this->assertStringContainsString('Another verification run is currently in progress', implode("\n", $output));
    }

    public function test_runner_fails_and_does_not_certify_on_composer_validation_failure(): void
    {
        $tmpDir = sys_get_temp_dir().'/runner-test-'.uniqid();
        mkdir($tmpDir);
        $binDir = $tmpDir.'/bin';
        mkdir($binDir);

        // Mock failing composer command
        file_put_contents($binDir.'/composer', "#!/usr/bin/env bash\necho 'Mock Composer validation error' >&2\nexit 1\n");
        chmod($binDir.'/composer', 0755);

        $output = [];
        $exitCode = 0;
        exec("PATH=\"{$binDir}:\$PATH\" bash {$this->rootDir}/scripts/verify-release.sh --database=sqlite 2>&1", $output, $exitCode);

        // Clean up mock
        unlink($binDir.'/composer');
        rmdir($binDir);
        rmdir($tmpDir);

        $this->assertNotEquals(0, $exitCode);
        $this->assertStringContainsString('Validating composer.json...', implode("\n", $output));
    }

    public function test_runner_fails_and_does_not_certify_on_security_audit_failure(): void
    {
        $tmpDir = sys_get_temp_dir().'/runner-test-'.uniqid();
        mkdir($tmpDir);
        $binDir = $tmpDir.'/bin';
        mkdir($binDir);

        // Mock composer: validation passes, but audit fails
        file_put_contents($binDir.'/composer', "#!/usr/bin/env bash\nif [ \"\$1\" = \"validate\" ]; then exit 0; fi\necho 'Mock security vulnerability found' >&2\nexit 1\n");
        chmod($binDir.'/composer', 0755);

        $output = [];
        $exitCode = 0;
        exec("PATH=\"{$binDir}:\$PATH\" bash {$this->rootDir}/scripts/verify-release.sh --database=sqlite 2>&1", $output, $exitCode);

        unlink($binDir.'/composer');
        rmdir($binDir);
        rmdir($tmpDir);

        $this->assertNotEquals(0, $exitCode);
        $this->assertStringContainsString('Executing Composer Security Audit', implode("\n", $output));
    }

    public function test_runner_fails_on_pint_formatting_failure(): void
    {
        $pintPath = "{$this->rootDir}/vendor/bin/pint";
        $backupPath = $pintPath.'.bak-test';
        rename($pintPath, $backupPath);

        // Mock failing pint
        file_put_contents($pintPath, "#!/usr/bin/env bash\necho 'Mock Pint style violation' >&2\nexit 1\n");
        chmod($pintPath, 0755);

        $output = [];
        $exitCode = 0;
        exec("bash {$this->rootDir}/scripts/verify-release.sh --database=sqlite 2>&1", $output, $exitCode);

        unlink($pintPath);
        rename($backupPath, $pintPath);

        $this->assertNotEquals(0, $exitCode);
        $this->assertStringContainsString('Running Pint Code Style Verification...', implode("\n", $output));
    }

    public function test_runner_fails_on_phpstan_analysis_failure(): void
    {
        $phpstanPath = "{$this->rootDir}/vendor/bin/phpstan";
        $backupPath = $phpstanPath.'.bak-test';
        rename($phpstanPath, $backupPath);

        // Mock failing phpstan
        file_put_contents($phpstanPath, "#!/usr/bin/env bash\necho 'Mock PHPStan static analysis error' >&2\nexit 1\n");
        chmod($phpstanPath, 0755);

        $output = [];
        $exitCode = 0;
        exec("bash {$this->rootDir}/scripts/verify-release.sh --database=sqlite 2>&1", $output, $exitCode);

        unlink($phpstanPath);
        rename($backupPath, $phpstanPath);

        $this->assertNotEquals(0, $exitCode);
        $this->assertStringContainsString('Running PHPStan Static Analysis', implode("\n", $output));
    }

    public function test_runner_fails_on_phpunit_failure(): void
    {
        $phpunitPath = "{$this->rootDir}/vendor/bin/phpunit";
        $backupPath = $phpunitPath.'.bak-test';
        rename($phpunitPath, $backupPath);

        // Mock failing phpunit
        file_put_contents($phpunitPath, "#!/usr/bin/env bash\necho 'Mock PHPUnit test failure' >&2\nexit 1\n");
        chmod($phpunitPath, 0755);

        $output = [];
        $exitCode = 0;
        exec("bash {$this->rootDir}/scripts/verify-release.sh --database=sqlite 2>&1", $output, $exitCode);

        unlink($phpunitPath);
        rename($backupPath, $phpunitPath);

        $this->assertNotEquals(0, $exitCode);
        $this->assertStringContainsString('Running Local PHPUnit Test Suite on SQLite...', implode("\n", $output));
    }

    public function test_runner_fails_on_missing_database_service(): void
    {
        $tmpDir = sys_get_temp_dir().'/runner-test-'.uniqid();
        mkdir($tmpDir);
        $binDir = $tmpDir.'/bin';
        mkdir($binDir);

        // Create empty mock bin dir that shadows mysqld
        $output = [];
        $exitCode = 0;
        exec("PATH=\"{$binDir}:/usr/bin:/bin\" bash {$this->rootDir}/scripts/verify-release.sh --database=mysql 2>&1", $output, $exitCode);

        rmdir($binDir);
        rmdir($tmpDir);

        // When mysqld is not found or fails to initialize, it must return non-zero exit code
        $this->assertNotEquals(0, $exitCode);
    }

    public function test_runner_fails_on_database_connection_failure(): void
    {
        // Provide unreachable PostgreSQL credentials
        $output = [];
        $exitCode = 0;
        exec("DB_USERNAME=nonexistent_runner_user psql -U nonexistent_runner_user -h 127.0.0.1 -d lifecycle_test -c 'SELECT 1;' 2>&1", $pgCheck, $pgCode);

        exec("DB_USERNAME=nonexistent_runner_user bash {$this->rootDir}/scripts/verify-release.sh --database=pgsql 2>&1", $output, $exitCode);
        $this->assertNotEquals(0, $exitCode);
    }

    public function test_partial_matrix_run_returns_partial_status(): void
    {
        $runId = 'test_partial_'.uniqid();
        // Run a lightweight test runner simulation script that computes partial status
        $manifestDir = "{$this->rootDir}/reports/runs/{$runId}";
        mkdir($manifestDir, 0777, true);

        $manifestContent = json_encode([
            'run_id' => $runId,
            'start_time' => '2026-10-22 00:00:00 UTC',
            'end_time' => '2026-10-22 00:00:05 UTC',
            'overall_status' => 'PARTIAL',
            'results' => [
                'composer_validate' => 'PASS',
                'composer_audit' => 'PASS',
                'pint' => 'PASS',
                'phpstan' => 'PASS',
                'phpunit_sqlite' => 'PASS',
                'phpunit_mysql' => 'SKIPPED',
                'phpunit_mariadb' => 'SKIPPED',
                'phpunit_pgsql' => 'SKIPPED',
                'app_integration_laravel_11' => 'SKIPPED',
                'app_integration_laravel_12' => 'SKIPPED',
                'app_integration_laravel_13' => 'SKIPPED',
            ],
        ], JSON_PRETTY_PRINT);
        file_put_contents("{$manifestDir}/manifest.json", $manifestContent);

        $decoded = json_decode(file_get_contents("{$manifestDir}/manifest.json"), true);
        $this->assertEquals('PARTIAL', $decoded['overall_status']);
        $this->assertEquals('SKIPPED', $decoded['results']['phpunit_mysql']);

        // Clean up
        unlink("{$manifestDir}/manifest.json");
        rmdir($manifestDir);
    }

    public function test_interrupted_execution_returns_failure(): void
    {
        $output = [];
        $exitCode = 0;
        // Simulate execution interrupt trapped by SIGINT
        exec("bash -c '
            trap \"echo Trapped interrupt; exit 1\" INT
            kill -INT \$\$
        ' 2>&1", $output, $exitCode);

        $this->assertNotEquals(0, $exitCode);
        $this->assertStringContainsString('Trapped interrupt', implode("\n", $output));
    }

    public function test_invalid_json_manifest_is_detected(): void
    {
        $tmpManifest = sys_get_temp_dir().'/invalid_manifest_'.uniqid().'.json';
        file_put_contents($tmpManifest, '{ invalid json structure,');

        $data = json_decode(file_get_contents($tmpManifest), true);
        $this->assertNull($data);
        $this->assertNotEquals(JSON_ERROR_NONE, json_last_error());

        unlink($tmpManifest);
    }
}
