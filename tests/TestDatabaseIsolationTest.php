<?php

declare(strict_types=1);

namespace Clcbws\DatabaseLifecycleSuite\Tests;

use RuntimeException;

class TestDatabaseIsolationTest extends TestCase
{
    public function test_it_passes_for_valid_disposable_test_databases(): void
    {
        $this->assertSafeTestDatabase('testing');
        $this->assertTrue(true);
    }

    public function test_it_rejects_unconfigured_connections(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('is not configured');

        $this->assertSafeTestDatabase('non_existent_connection');
    }

    public function test_it_rejects_remote_unapproved_hosts(): void
    {
        config()->set('database.connections.remote_db', [
            'driver' => 'mysql',
            'host' => 'production-cluster.us-east-1.rds.amazonaws.com',
            'database' => 'lifecycle_test',
        ]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('is not an approved local/container test host');

        $this->assertSafeTestDatabase('remote_db');
    }

    public function test_it_rejects_forbidden_or_non_test_database_names(): void
    {
        config()->set('database.connections.unsafe_db', [
            'driver' => 'mysql',
            'host' => '127.0.0.1',
            'database' => 'production',
        ]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('lacks explicit disposable authorization');

        $this->assertSafeTestDatabase('unsafe_db');
    }

    public function test_it_rejects_generic_production_like_database_names(): void
    {
        config()->set('database.connections.generic_db', [
            'driver' => 'pgsql',
            'host' => 'localhost',
            'database' => 'laravel',
        ]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('lacks explicit disposable authorization');

        $this->assertSafeTestDatabase('generic_db');
    }

    public function test_it_rejects_when_explicit_authorization_is_disabled(): void
    {
        config()->set('database-lifecycle-suite.allow_destructive_test_cleanup', false);

        try {
            $this->assertSafeTestDatabase('testing');
            $this->fail('Expected RuntimeException was not thrown.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('Explicit test cleanup authorization is disabled', $e->getMessage());
        } finally {
            config()->set('database-lifecycle-suite.allow_destructive_test_cleanup', true);
        }
    }

    public function test_it_allows_approved_container_hosts(): void
    {
        config()->set('database.connections.container_mysql', [
            'driver' => 'mysql',
            'host' => 'mysql',
            'database' => 'lifecycle_test',
            'is_disposable' => true,
        ]);

        $this->assertSafeTestDatabase('container_mysql');
        $this->assertTrue(true);
    }
}
