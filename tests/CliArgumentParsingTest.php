<?php

declare(strict_types=1);

namespace Clcbws\DatabaseLifecycleSuite\Tests;

use PHPUnit\Framework\Attributes\Test;

/**
 * Class CliArgumentParsingTest
 *
 * Verifies argument parsing in both verify-release.sh and run-app-integration.sh.
 */
class CliArgumentParsingTest extends TestCase
{
    protected string $verifyScript;

    protected string $integrationScript;

    protected function setUp(): void
    {
        parent::setUp();
        $this->verifyScript = dirname(__DIR__).'/scripts/verify-release.sh';
        $this->integrationScript = dirname(__DIR__).'/tests/integration-app/scripts/run-app-integration.sh';
    }

    #[Test]
    public function it_rejects_unknown_arguments_in_integration_runner(): void
    {
        $output = [];
        $exitCode = 0;
        exec("bash {$this->integrationScript} --invalid-arg 2>&1", $output, $exitCode);

        $this->assertNotSame(0, $exitCode, 'Integration runner should exit with non-zero on unknown argument');
        $this->assertStringContainsString('ERROR: Unknown argument', implode("\n", $output));
    }

    #[Test]
    public function it_rejects_unknown_arguments_in_verification_runner(): void
    {
        $output = [];
        $exitCode = 0;
        exec("bash {$this->verifyScript} --unknown-flag 2>&1", $output, $exitCode);

        $this->assertNotSame(0, $exitCode, 'Verification runner should exit with non-zero on unknown argument');
        $this->assertStringContainsString('ERROR: Unknown argument', implode("\n", $output));
    }

    #[Test]
    public function it_rejects_invalid_laravel_flag_in_integration_runner(): void
    {
        $output = [];
        $exitCode = 0;
        exec("bash {$this->integrationScript} --laravel=99 2>&1", $output, $exitCode);

        $this->assertNotSame(0, $exitCode);
        $this->assertStringContainsString('ERROR: Unknown argument', implode("\n", $output));
    }

    #[Test]
    public function it_rejects_invalid_database_flag_in_verification_runner(): void
    {
        $output = [];
        $exitCode = 0;
        exec("bash {$this->verifyScript} --database=oracle 2>&1", $output, $exitCode);

        $this->assertNotSame(0, $exitCode);
        $this->assertStringContainsString('ERROR: Invalid database target', implode("\n", $output));
    }
}
