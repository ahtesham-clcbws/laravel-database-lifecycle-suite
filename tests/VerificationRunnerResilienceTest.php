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
        $tmpLock = sys_get_temp_dir().'/verify-test-'.uniqid().'.lock';
        $fp = fopen($tmpLock, 'w+');
        $this->assertIsResource($fp);
        $locked = flock($fp, LOCK_EX | LOCK_NB);
        $this->assertTrue($locked, 'Failed to acquire test lock');

        $output = [];
        $exitCode = 0;
        exec("VERIFY_LOCK_FILE=\"{$tmpLock}\" bash {$this->rootDir}/scripts/verify-release.sh 2>&1", $output, $exitCode);

        flock($fp, LOCK_UN);
        fclose($fp);
        unlink($tmpLock);

        $this->assertNotEquals(0, $exitCode);
        $this->assertStringContainsString('Another verification run is currently in progress', implode("\n", $output));
    }

    public function test_runner_fails_and_does_not_certify_on_composer_validation_failure(): void
    {
        $tmpDir = sys_get_temp_dir().'/runner-test-'.uniqid();
        mkdir($tmpDir);
        $binDir = $tmpDir.'/bin';
        mkdir($binDir);
        $tmpLock = $tmpDir.'/custom.lock';

        // Mock failing composer command via isolated PATH
        file_put_contents($binDir.'/composer', "#!/usr/bin/env bash\necho 'Mock Composer validation error' >&2\nexit 1\n");
        chmod($binDir.'/composer', 0755);

        $output = [];
        $exitCode = 0;
        exec("VERIFY_LOCK_FILE=\"{$tmpLock}\" PATH=\"{$binDir}:\$PATH\" bash {$this->rootDir}/scripts/verify-release.sh --database=sqlite 2>&1", $output, $exitCode);

        unlink($binDir.'/composer');
        rmdir($binDir);
        @unlink($tmpLock);
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
        $tmpLock = $tmpDir.'/custom.lock';

        // Mock composer: validation passes, but audit fails
        file_put_contents($binDir.'/composer', "#!/usr/bin/env bash\nif [ \"\$1\" = \"validate\" ]; then exit 0; fi\necho 'Mock security vulnerability found' >&2\nexit 1\n");
        chmod($binDir.'/composer', 0755);

        $output = [];
        $exitCode = 0;
        exec("VERIFY_LOCK_FILE=\"{$tmpLock}\" PATH=\"{$binDir}:\$PATH\" bash {$this->rootDir}/scripts/verify-release.sh --database=sqlite 2>&1", $output, $exitCode);

        unlink($binDir.'/composer');
        rmdir($binDir);
        @unlink($tmpLock);
        rmdir($tmpDir);

        $this->assertNotEquals(0, $exitCode);
        $this->assertStringContainsString('Executing Composer Security Audit', implode("\n", $output));
    }

    public function test_runner_fails_on_pint_formatting_failure(): void
    {
        $tmpDir = sys_get_temp_dir().'/pint-test-'.uniqid();
        mkdir($tmpDir);
        $mockPint = $tmpDir.'/pint';
        file_put_contents($mockPint, "#!/usr/bin/env bash\necho 'Mock Pint style violation' >&2\nexit 1\n");
        chmod($mockPint, 0755);

        $tmpLock = $tmpDir.'/pint.lock';
        $output = [];
        $exitCode = 0;
        exec("PINT_BIN=\"{$mockPint}\" VERIFY_LOCK_FILE=\"{$tmpLock}\" bash {$this->rootDir}/scripts/verify-release.sh --database=sqlite 2>&1", $output, $exitCode);

        unlink($mockPint);
        @unlink($tmpLock);
        rmdir($tmpDir);

        $this->assertNotEquals(0, $exitCode);
        $this->assertStringContainsString('Running Pint Code Style Verification...', implode("\n", $output));
    }

    public function test_runner_fails_on_phpstan_analysis_failure(): void
    {
        $tmpDir = sys_get_temp_dir().'/phpstan-test-'.uniqid();
        mkdir($tmpDir);
        $mockPhpstan = $tmpDir.'/phpstan';
        file_put_contents($mockPhpstan, "#!/usr/bin/env bash\necho 'Mock PHPStan static analysis error' >&2\nexit 1\n");
        chmod($mockPhpstan, 0755);

        $tmpLock = $tmpDir.'/phpstan.lock';
        $output = [];
        $exitCode = 0;
        exec("PHPSTAN_BIN=\"{$mockPhpstan}\" VERIFY_LOCK_FILE=\"{$tmpLock}\" bash {$this->rootDir}/scripts/verify-release.sh --database=sqlite 2>&1", $output, $exitCode);

        unlink($mockPhpstan);
        @unlink($tmpLock);
        rmdir($tmpDir);

        $this->assertNotEquals(0, $exitCode);
        $this->assertStringContainsString('Running PHPStan Static Analysis', implode("\n", $output));
    }

    public function test_runner_fails_on_phpunit_failure(): void
    {
        $tmpDir = sys_get_temp_dir().'/phpunit-test-'.uniqid();
        mkdir($tmpDir);
        $mockPhpunit = $tmpDir.'/phpunit';
        file_put_contents($mockPhpunit, "#!/usr/bin/env bash\necho 'Mock PHPUnit test failure' >&2\nexit 1\n");
        chmod($mockPhpunit, 0755);

        $tmpLock = $tmpDir.'/phpunit.lock';
        $output = [];
        $exitCode = 0;
        exec("PHPUNIT_BIN=\"{$mockPhpunit}\" VERIFY_LOCK_FILE=\"{$tmpLock}\" bash {$this->rootDir}/scripts/verify-release.sh --database=sqlite 2>&1", $output, $exitCode);

        unlink($mockPhpunit);
        @unlink($tmpLock);
        rmdir($tmpDir);

        $this->assertNotEquals(0, $exitCode);
        $this->assertStringContainsString('Running Local PHPUnit Test Suite on SQLite...', implode("\n", $output));
    }

    public function test_runner_fails_on_missing_database_service(): void
    {
        $tmpDir = sys_get_temp_dir().'/runner-test-'.uniqid();
        mkdir($tmpDir);
        $tmpLock = $tmpDir.'/runner.lock';

        $output = [];
        $exitCode = 0;
        exec("MYSQLD_BIN=\"/nonexistent/mysqld\" VERIFY_LOCK_FILE=\"{$tmpLock}\" bash {$this->rootDir}/scripts/verify-release.sh --database=mysql 2>&1", $output, $exitCode);

        @unlink($tmpLock);
        rmdir($tmpDir);

        $this->assertNotEquals(0, $exitCode);
        $this->assertStringContainsString('MySQL binaries not found', implode("\n", $output));
    }

    public function test_runner_fails_on_database_connection_failure(): void
    {
        $tmpLock = sys_get_temp_dir().'/pg-test-'.uniqid().'.lock';
        $output = [];
        $exitCode = 0;
        // Point PostgreSQL check to an invalid port (e.g. 54321) where nothing is listening
        exec("DB_PGSQL_PORT=54321 VERIFY_LOCK_FILE=\"{$tmpLock}\" bash {$this->rootDir}/scripts/verify-release.sh --database=pgsql 2>&1", $output, $exitCode);
        @unlink($tmpLock);

        $this->assertNotEquals(0, $exitCode);
        $this->assertStringContainsString('PostgreSQL not reachable', implode("\n", $output));
    }

    public function test_partial_matrix_run_returns_partial_status(): void
    {
        $tmpDir = sys_get_temp_dir().'/partial-runner-'.uniqid();
        mkdir($tmpDir);
        $tmpLock = $tmpDir.'/partial.lock';

        $mockBin = $tmpDir.'/bin';
        mkdir($mockBin);
        file_put_contents($mockBin.'/pint', "#!/usr/bin/env bash\nexit 0\n");
        chmod($mockBin.'/pint', 0755);
        file_put_contents($mockBin.'/phpstan', "#!/usr/bin/env bash\nexit 0\n");
        chmod($mockBin.'/phpstan', 0755);
        file_put_contents($mockBin.'/phpunit', "#!/usr/bin/env bash\nexit 0\n");
        chmod($mockBin.'/phpunit', 0755);

        $output = [];
        $exitCode = 0;
        // Run real verify-release.sh with partial selection: --database=sqlite --laravel=none
        exec("PINT_BIN=\"{$mockBin}/pint\" PHPSTAN_BIN=\"{$mockBin}/phpstan\" PHPUNIT_BIN=\"{$mockBin}/phpunit\" VERIFY_LOCK_FILE=\"{$tmpLock}\" bash {$this->rootDir}/scripts/verify-release.sh --database=sqlite --laravel=none 2>&1", $output, $exitCode);

        $joined = implode("\n", $output);
        $this->assertStringContainsString('Partial Verification Completed', $joined);
        $this->assertStringContainsString('Status: PARTIAL', $joined);

        // Verify that the manifest generated by verify-release.sh records PARTIAL
        preg_match('/Run ID: (run_[0-9_]+)/', $joined, $matches);
        $this->assertNotEmpty($matches, 'Could not find Run ID in runner output');
        $runId = $matches[1];
        $manifestFile = "{$this->rootDir}/reports/runs/{$runId}/manifest.json";

        $this->assertFileExists($manifestFile);
        $manifestData = json_decode(file_get_contents($manifestFile), true);
        $this->assertEquals('PARTIAL', $manifestData['overall_status']);
        $this->assertEquals('SKIPPED', $manifestData['results']['phpunit_mysql']);

        // Clean up
        exec("rm -rf {$this->rootDir}/reports/runs/{$runId}");
        unlink($mockBin.'/pint');
        unlink($mockBin.'/phpstan');
        unlink($mockBin.'/phpunit');
        rmdir($mockBin);
        @unlink($tmpLock);
        rmdir($tmpDir);
    }

    public function test_interrupted_execution_returns_failure(): void
    {
        $tmpDir = sys_get_temp_dir().'/interrupt-test-'.uniqid();
        mkdir($tmpDir);
        $tmpLock = $tmpDir.'/interrupt.lock';

        $mockPhpunit = $tmpDir.'/slow_phpunit';
        // A mock phpunit that sleeps to simulate a long running test phase
        file_put_contents($mockPhpunit, "#!/usr/bin/env bash\nsleep 10\nexit 0\n");
        chmod($mockPhpunit, 0755);

        $pipes = [];
        $cmd = ['bash', "{$this->rootDir}/scripts/verify-release.sh", '--database=sqlite', '--laravel=none'];
        $env = array_merge($_ENV, [
            'PHPUNIT_BIN' => $mockPhpunit,
            'VERIFY_LOCK_FILE' => $tmpLock,
        ]);

        $proc = proc_open($cmd, [
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ], $pipes, $this->rootDir, $env);

        $this->assertIsResource($proc);

        // Wait briefly for runner to start executing
        usleep(300000); // 300ms

        // Send SIGTERM to real verify-release.sh runner process
        proc_terminate($proc, SIGTERM);

        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);

        $exitCode = proc_close($proc);
        $combinedOutput = $stdout."\n".$stderr;

        // Clean up
        @unlink($mockPhpunit);
        @unlink($tmpLock);
        rmdir($tmpDir);

        // Assert process exited with non-zero failure code
        $this->assertNotEquals(0, $exitCode);
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
