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
            'is_disposable' => true,
            'test_database_owner' => 'laravel-database-lifecycle-suite',
        ]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('is a forbidden production or system database name');

        $this->assertSafeTestDatabase('unsafe_db');
    }

    public function test_it_rejects_generic_production_like_database_names(): void
    {
        config()->set('database.connections.generic_db', [
            'driver' => 'pgsql',
            'host' => 'localhost',
            'database' => 'laravel',
            'is_disposable' => true,
            'test_database_owner' => 'laravel-database-lifecycle-suite',
        ]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('is a forbidden production or system database name');

        $this->assertSafeTestDatabase('generic_db');
    }

    public function test_it_rejects_unwhitelisted_database_names(): void
    {
        config()->set('database.connections.custom_db', [
            'driver' => 'mysql',
            'host' => '127.0.0.1',
            'database' => 'some_unapproved_database',
            'is_disposable' => true,
            'test_database_owner' => 'laravel-database-lifecycle-suite',
        ]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('is not on the authorized disposable test database whitelist');

        $this->assertSafeTestDatabase('custom_db');
    }

    public function test_it_rejects_sql_injection_in_database_identifier(): void
    {
        config()->set('database.connections.injected_db', [
            'driver' => 'mysql',
            'host' => '127.0.0.1',
            'database' => 'lifecycle_test; DROP TABLE users; --',
            'is_disposable' => true,
            'test_database_owner' => 'laravel-database-lifecycle-suite',
        ]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Invalid database identifier');

        $this->assertSafeTestDatabase('injected_db');
    }

    public function test_it_rejects_sql_injection_in_source_database_provisioning(): void
    {
        config()->set('database.connections.injected_source', [
            'driver' => 'mysql',
            'host' => '127.0.0.1',
            'database' => 'lifecycle_source; DROP TABLE users; --',
            'is_disposable' => true,
            'test_database_owner' => 'laravel-database-lifecycle-suite',
        ]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Invalid database identifier');

        $this->ensureSourceDatabaseExists('testing', 'injected_source');
    }

    public function test_it_rejects_missing_owner_marker(): void
    {
        config()->set('database.connections.unowned_db', [
            'driver' => 'mysql',
            'host' => '127.0.0.1',
            'database' => 'lifecycle_test',
            'is_disposable' => true,
            // missing 'test_database_owner'
        ]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage("lacks explicit disposable database ownership ('is_disposable' and 'test_database_owner')");

        $this->assertSafeTestDatabase('unowned_db');
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
            'test_database_owner' => 'laravel-database-lifecycle-suite',
        ]);

        $this->assertSafeTestDatabase('container_mysql');
        $this->assertTrue(true);
    }

    public function test_it_rejects_unverified_runtime_identities_on_down_services(): void
    {
        config()->set('database.connections.offline_mysql', [
            'driver' => 'mysql',
            'host' => '127.0.0.1',
            'port' => '59999', // Port with no server
            'database' => 'lifecycle_test',
            'username' => 'root',
            'password' => '',
            'is_disposable' => true,
            'test_database_owner' => 'laravel-database-lifecycle-suite',
        ]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Failed to verify live database identity');

        // Require runtime verification must fail closed if connection cannot be verified
        $this->assertSafeTestDatabase('offline_mysql', true);
    }

    public function test_it_rejects_unisolated_shared_source_and_destination(): void
    {
        config()->set('database.connections.source_shared', [
            'driver' => 'mysql',
            'host' => '127.0.0.1',
            'port' => '3306',
            'database' => 'lifecycle_test',
        ]);

        config()->set('database.connections.dest_shared', [
            'driver' => 'mysql',
            'host' => '127.0.0.1',
            'port' => '3306',
            'database' => 'lifecycle_test',
        ]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Source and destination isolation violation: Source and destination share the same database [lifecycle_test]');

        $this->assertSourceAndDestinationIsolated('source_shared', 'dest_shared');
    }

    public function test_it_verifies_source_and_destination_isolation_success(): void
    {
        config()->set('database.connections.source_iso', [
            'driver' => 'mysql',
            'host' => '127.0.0.1',
            'port' => '3306',
            'database' => 'lifecycle_source_test',
        ]);

        config()->set('database.connections.dest_iso', [
            'driver' => 'mysql',
            'host' => '127.0.0.1',
            'port' => '3306',
            'database' => 'lifecycle_test',
        ]);

        $this->assertSourceAndDestinationIsolated('source_iso', 'dest_iso');
        $this->assertTrue(true);
    }
}
