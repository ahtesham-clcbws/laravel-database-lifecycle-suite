<?php

declare(strict_types=1);

namespace Clcbws\DatabaseLifecycleSuite\Tests;

use Clcbws\DatabaseLifecycleSuite\SoftDeleteAuditor;
use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;

class SoftDeleteAuditorTest extends TestCase
{
    protected SoftDeleteAuditor $auditor;

    protected function setUp(): void
    {
        parent::setUp();
        $this->auditor = new SoftDeleteAuditor();
    }

    public function test_it_is_stateless_and_can_be_instantiated()
    {
        $this->assertInstanceOf(SoftDeleteAuditor::class, $this->auditor);
    }
}
