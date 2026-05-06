<?php

declare(strict_types=1);

namespace Clcbws\DatabaseLifecycleSuite\Tests;

use Clcbws\DatabaseLifecycleSuite\CastAuditor;
use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;

class CastAuditorTest extends TestCase
{
    protected CastAuditor $auditor;

    protected function setUp(): void
    {
        parent::setUp();
        $this->auditor = new CastAuditor();
    }

    public function test_it_is_stateless_and_can_be_instantiated()
    {
        $this->assertInstanceOf(CastAuditor::class, $this->auditor);
    }
    
    // Note: Testing actual model reflection requires real files in app/Models.
    // For package tests, we'd typically mock the file system or use a temporary model directory.
}
