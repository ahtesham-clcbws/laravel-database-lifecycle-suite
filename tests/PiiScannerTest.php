<?php

declare(strict_types=1);

namespace Clcbws\DatabaseLifecycleSuite\Tests;

use Clcbws\DatabaseLifecycleSuite\PiiScanner;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class PiiScannerTest extends TestCase
{
    protected PiiScanner $scanner;

    protected function setUp(): void
    {
        parent::setUp();
        $this->scanner = new PiiScanner;

        config(['database-lifecycle-suite.pii_patterns' => [
            'email' => '/email/i',
            'phone' => '/phone|mobile|tel/i',
        ]]);

        Schema::create('customers', function (Blueprint $table) {
            $table->id();
            $table->string('customer_email');
            $table->string('mobile_number');
            $table->string('internal_id'); // Not PII
        });
    }

    public function test_it_identifies_pii_columns()
    {
        $findings = $this->scanner->scan();

        $this->assertCount(2, $findings);

        $emails = \collect($findings)->where('category', 'Email');
        $this->assertCount(1, $emails);
        $this->assertEquals('customer_email', $emails->first()['column']);

        $phones = \collect($findings)->where('category', 'Phone');
        $this->assertCount(1, $phones);
        $this->assertEquals('mobile_number', $phones->first()['column']);
    }

    public function test_pii_scan_command_respects_strict_flag(): void
    {
        $this->artisan('db:pii-scan', ['--strict' => true])
            ->expectsOutputToContain('potential sensitive columns')
            ->assertExitCode(1);
    }
}
