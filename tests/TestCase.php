<?php

declare(strict_types=1);

namespace Clcbws\DatabaseLifecycleSuite\Tests;

use Clcbws\DatabaseLifecycleSuite\DatabaseLifecycleSuiteServiceProvider;
use Illuminate\Foundation\Application;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Orchestra\Testbench\TestCase as Orchestra;

/**
 * Class TestCase
 *
 * Base test class for the suite, utilizing Orchestra Testbench to simulate
 * a Laravel application environment.
 */
class TestCase extends Orchestra
{
    /**
     * Get the package providers for the test environment.
     *
     * @param  Application  $app
     */
    protected function getPackageProviders($app): array
    {
        return [
            DatabaseLifecycleSuiteServiceProvider::class,
        ];
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->cleanDatabase();
    }

    protected function tearDown(): void
    {
        $this->cleanDatabase();

        foreach (['mysql', 'pgsql', 'source', 'testing'] as $conn) {
            try {
                DB::disconnect($conn);
            } catch (\Throwable) {
            }
        }

        parent::tearDown();
    }

    /**
     * Wipe all tables on persistent multi-database test connections between test runs.
     * Enforces strict fail-closed validation of disposable test databases before dropping tables.
     */
    protected function cleanDatabase(): void
    {
        $defaultConn = (string) config('database.default', 'testing');
        if (in_array($defaultConn, ['mysql', 'pgsql'], true)) {
            $this->assertSafeTestDatabase($defaultConn, true);
            Schema::connection($defaultConn)->dropAllTables();

            $sourceDriver = (string) config('database.connections.source.driver', '');
            if (in_array($sourceDriver, ['mysql', 'pgsql'], true)) {
                $this->ensureSourceDatabaseExists($defaultConn, 'source');
                $this->assertSafeTestDatabase('source', true);
                $this->assertSourceAndDestinationIsolated('source', $defaultConn);
                Schema::connection('source')->dropAllTables();
            }
        }
    }

    /**
     * Ensure the isolated source database exists before attempting connection.
     */
    protected function ensureSourceDatabaseExists(string $adminConnection, string $sourceConnection): void
    {
        $sourceConfig = config("database.connections.{$sourceConnection}");
        if (! is_array($sourceConfig)) {
            return;
        }

        $sourceDb = (string) ($sourceConfig['database'] ?? '');
        if ($sourceDb === '' || $sourceDb === ':memory:') {
            return;
        }

        $driver = (string) ($sourceConfig['driver'] ?? '');
        try {
            if (in_array($driver, ['mysql', 'mariadb'], true)) {
                DB::connection($adminConnection)->statement("CREATE DATABASE IF NOT EXISTS `{$sourceDb}`;");
            } elseif ($driver === 'pgsql') {
                $exists = DB::connection($adminConnection)->scalar("SELECT 1 FROM pg_database WHERE datname = '{$sourceDb}'");
                if (! $exists) {
                    DB::connection($adminConnection)->statement("CREATE DATABASE \"{$sourceDb}\";");
                }
            }
        } catch (\Throwable) {
            // If creation fails due to permissions, downstream checks will verify identity safely
        }
    }

    /**
     * Verify that source and destination connections are strictly isolated databases.
     *
     * @throws \RuntimeException
     */
    public function assertSourceAndDestinationIsolated(string $sourceConnection = 'source', ?string $destConnection = null): void
    {
        $destConnection = $destConnection ?: (string) config('database.default', 'testing');
        if ($sourceConnection === $destConnection) {
            throw new \RuntimeException("Source and destination isolation violation: Source and destination share the same connection name [{$sourceConnection}].");
        }

        $sourceConfig = config("database.connections.{$sourceConnection}");
        $destConfig = config("database.connections.{$destConnection}");

        if (! is_array($sourceConfig) || ! is_array($destConfig)) {
            return;
        }

        $sourceDriver = (string) ($sourceConfig['driver'] ?? '');
        $destDriver = (string) ($destConfig['driver'] ?? '');
        $sourceDb = (string) ($sourceConfig['database'] ?? '');
        $destDb = (string) ($destConfig['database'] ?? '');
        $sourceHost = (string) ($sourceConfig['host'] ?? '127.0.0.1');
        $destHost = (string) ($destConfig['host'] ?? '127.0.0.1');
        $sourcePort = (string) ($sourceConfig['port'] ?? '');
        $destPort = (string) ($destConfig['port'] ?? '');

        // Persistent databases sharing identical host, port, and database name are NOT isolated
        if (in_array($destDriver, ['mysql', 'mariadb', 'pgsql'], true) && in_array($sourceDriver, ['mysql', 'mariadb', 'pgsql'], true)) {
            if (strtolower($sourceDb) === strtolower($destDb) && strtolower($sourceHost) === strtolower($destHost) && $sourcePort === $destPort) {
                throw new \RuntimeException("Source and destination isolation violation: Source and destination share the same database [{$sourceDb}] on host [{$sourceHost}].");
            }
        }

        // SQLite file databases
        if ($sourceDriver === 'sqlite' && $destDriver === 'sqlite' && $sourceDb !== ':memory:' && $destDb !== ':memory:') {
            if (realpath($sourceDb) !== false && realpath($sourceDb) === realpath($destDb)) {
                throw new \RuntimeException("Source and destination isolation violation: Source and destination share the same SQLite file [{$sourceDb}].");
            }
        }
    }

    /**
     * Verify that a connection is explicitly designated as a disposable test database.
     * Fails closed by throwing a RuntimeException if any safety check fails.
     *
     * @throws \RuntimeException
     */
    public function assertSafeTestDatabase(string $connection, bool $requireRuntimeVerification = false): void
    {
        // 1. Explicit cleanup authorization check
        if (! config('database-lifecycle-suite.allow_destructive_test_cleanup', false)) {
            throw new \RuntimeException('Destructive test database cleanup refused: Explicit test cleanup authorization is disabled.');
        }

        // 2. Environment must strictly be 'testing'
        if (app()->environment() !== 'testing') {
            throw new \RuntimeException('Destructive test database cleanup refused: APP_ENV is ['.app()->environment().'], not [testing].');
        }

        $config = config("database.connections.{$connection}");
        if (! is_array($config) || empty($config)) {
            throw new \RuntimeException("Destructive test database cleanup refused: Connection [{$connection}] is not configured.");
        }

        $driver = (string) ($config['driver'] ?? '');
        $database = (string) ($config['database'] ?? '');

        // 3. In-memory SQLite is inherently disposable
        if ($driver === 'sqlite') {
            if ($database === ':memory:') {
                return;
            }
            if (! empty($config['is_disposable']) && ($config['test_database_owner'] ?? '') === 'laravel-database-lifecycle-suite') {
                return;
            }
            throw new \RuntimeException("Destructive test cleanup refused: SQLite database [{$database}] lacks explicit disposable authorization or test ownership.");
        }

        // 4. Reject non-local/unknown hosts for persistent engines
        $host = (string) ($config['host'] ?? '127.0.0.1');
        $allowedHosts = ['127.0.0.1', 'localhost', '::1', 'mysql', 'mariadb', 'postgres', 'postgresql'];
        if (! in_array(strtolower($host), $allowedHosts, true) && ! str_ends_with($host, '.internal') && ! str_ends_with($host, '.local')) {
            throw new \RuntimeException("Destructive test database cleanup refused: Host [{$host}] is not an approved local/container test host.");
        }

        // 5. Explicit disposable authorization and ownership contract
        $authorizedDatabases = ['lifecycle_test', 'lifecycle_source_test', 'testing'];
        if (! in_array($database, $authorizedDatabases, true)) {
            throw new \RuntimeException("Destructive test database cleanup refused: Database [{$database}] is not on the authorized disposable test database whitelist.");
        }

        $hasDisposableFlag = ! empty($config['is_disposable']);
        $hasOwnerMarker = ($config['test_database_owner'] ?? '') === 'laravel-database-lifecycle-suite';
        if (! $hasDisposableFlag || ! $hasOwnerMarker) {
            throw new \RuntimeException("Destructive test database cleanup refused: Database [{$database}] lacks explicit disposable database ownership ('is_disposable' and 'test_database_owner').");
        }

        // 6. Runtime identity verification: ensure active socket database matches authorized database
        if ($requireRuntimeVerification) {
            try {
                $connInstance = DB::connection($connection);
                if (in_array($driver, ['mysql', 'mariadb'], true)) {
                    $runtimeDb = (string) $connInstance->scalar('SELECT DATABASE()');
                } elseif ($driver === 'pgsql') {
                    $runtimeDb = (string) $connInstance->scalar('SELECT current_database()');
                } else {
                    $runtimeDb = $database;
                }

                if ($runtimeDb === '' || strtolower($runtimeDb) !== strtolower($database)) {
                    throw new \RuntimeException("Live database identity [{$runtimeDb}] does not match expected authorized database [{$database}].");
                }
            } catch (\Throwable $e) {
                throw new \RuntimeException("Destructive test database cleanup refused: Failed to verify live database identity for [{$connection}]: ".$e->getMessage(), 0, $e);
            }
        }
    }

    /**
     * Define the database environment for tests.
     *
     * @param  Application  $app
     */
    protected function defineEnvironment($app): void
    {
        $app['config']->set('database-lifecycle-suite.allow_destructive_test_cleanup', true);

        $dbDriver = getenv('DB_CONNECTION');

        if ($dbDriver === 'mysql') {
            $app['config']->set('database.default', 'mysql');
            $app['config']->set('database.connections.mysql', [
                'driver' => 'mysql',
                'host' => getenv('DB_HOST') ?: '127.0.0.1',
                'port' => getenv('DB_PORT') ?: '3306',
                'database' => getenv('DB_DATABASE') ?: 'lifecycle_test',
                'username' => getenv('DB_USERNAME') ?: 'root',
                'password' => getenv('DB_PASSWORD') ?: '',
                'prefix' => '',
                'is_disposable' => true,
                'test_database_owner' => 'laravel-database-lifecycle-suite',
            ]);
            $app['config']->set('database.connections.source', [
                'driver' => 'mysql',
                'host' => getenv('DB_HOST') ?: '127.0.0.1',
                'port' => getenv('DB_PORT') ?: '3306',
                'database' => getenv('DB_SOURCE_DATABASE') ?: 'lifecycle_source_test',
                'username' => getenv('DB_USERNAME') ?: 'root',
                'password' => getenv('DB_PASSWORD') ?: '',
                'prefix' => '',
                'is_disposable' => true,
                'test_database_owner' => 'laravel-database-lifecycle-suite',
            ]);
        } elseif ($dbDriver === 'pgsql') {
            $app['config']->set('database.default', 'pgsql');
            $app['config']->set('database.connections.pgsql', [
                'driver' => 'pgsql',
                'host' => getenv('DB_HOST') ?: '127.0.0.1',
                'port' => getenv('DB_PORT') ?: '5432',
                'database' => getenv('DB_DATABASE') ?: 'lifecycle_test',
                'username' => getenv('DB_USERNAME') ?: 'postgres',
                'password' => getenv('DB_PASSWORD') ?: '',
                'prefix' => '',
                'is_disposable' => true,
                'test_database_owner' => 'laravel-database-lifecycle-suite',
            ]);
            $app['config']->set('database.connections.source', [
                'driver' => 'pgsql',
                'host' => getenv('DB_HOST') ?: '127.0.0.1',
                'port' => getenv('DB_PORT') ?: '5432',
                'database' => getenv('DB_SOURCE_DATABASE') ?: 'lifecycle_source_test',
                'username' => getenv('DB_USERNAME') ?: 'postgres',
                'password' => getenv('DB_PASSWORD') ?: '',
                'prefix' => '',
                'is_disposable' => true,
                'test_database_owner' => 'laravel-database-lifecycle-suite',
            ]);
        } else {
            $app['config']->set('database.default', 'testing');
            $app['config']->set('database.connections.testing', [
                'driver' => 'sqlite',
                'database' => ':memory:',
                'prefix' => '',
                'is_disposable' => true,
                'test_database_owner' => 'laravel-database-lifecycle-suite',
            ]);
            $app['config']->set('database.connections.source', [
                'driver' => 'sqlite',
                'database' => ':memory:',
                'prefix' => '',
                'is_disposable' => true,
                'test_database_owner' => 'laravel-database-lifecycle-suite',
            ]);
        }

        $app['config']->set('database-lifecycle-suite.source_connection', 'source');
        $app['config']->set('database-lifecycle-suite.model_path', 'app/Models');
    }
}
