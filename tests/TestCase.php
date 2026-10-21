<?php

declare(strict_types=1);

namespace Clcbws\DatabaseLifecycleSuite\Tests;

use Clcbws\DatabaseLifecycleSuite\DatabaseLifecycleSuiteServiceProvider;
use Illuminate\Foundation\Application;
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
            $this->assertSafeTestDatabase($defaultConn);
            Schema::connection($defaultConn)->dropAllTables();

            $sourceDriver = (string) config('database.connections.source.driver', '');
            if (in_array($sourceDriver, ['mysql', 'pgsql'], true)) {
                $this->assertSafeTestDatabase('source');
                Schema::connection('source')->dropAllTables();
            }
        }
    }

    /**
     * Verify that a connection is explicitly designated as a disposable test database.
     * Fails closed by throwing a RuntimeException if any safety check fails.
     *
     * @throws \RuntimeException
     */
    public function assertSafeTestDatabase(string $connection): void
    {
        // 1. Environment must strictly be 'testing'
        if (app()->environment() !== 'testing') {
            throw new \RuntimeException('Destructive test database cleanup refused: APP_ENV is ['.app()->environment().'], not [testing].');
        }

        $config = config("database.connections.{$connection}");
        if (! is_array($config) || empty($config)) {
            throw new \RuntimeException("Destructive test database cleanup refused: Connection [{$connection}] is not configured.");
        }

        $driver = (string) ($config['driver'] ?? '');
        $database = (string) ($config['database'] ?? '');

        // 2. In-memory SQLite is inherently disposable
        if ($driver === 'sqlite') {
            if ($database === ':memory:' || str_contains($database, 'test') || str_contains($database, 'tmp')) {
                return;
            }
            throw new \RuntimeException("Destructive test cleanup refused: SQLite database [{$database}] does not appear disposable.");
        }

        // 3. Reject non-local/unknown hosts for persistent engines
        $host = (string) ($config['host'] ?? '127.0.0.1');
        $allowedHosts = ['127.0.0.1', 'localhost', '::1', 'mysql', 'mariadb', 'postgres', 'postgresql'];
        if (! in_array(strtolower($host), $allowedHosts, true) && ! str_ends_with($host, '.internal') && ! str_ends_with($host, '.local')) {
            throw new \RuntimeException("Destructive test database cleanup refused: Host [{$host}] is not an approved local/container test host.");
        }

        // 4. Database name must explicitly contain test markers
        $lowerDb = strtolower($database);
        $hasTestMarker = str_contains($lowerDb, 'test') || str_contains($lowerDb, 'lifecycle') || str_ends_with($lowerDb, '_tmp');
        $isForbidden = in_array($lowerDb, ['production', 'prod', 'master', 'main', 'live', 'app', 'laravel'], true);

        if (! $hasTestMarker || $isForbidden) {
            throw new \RuntimeException("Destructive test database cleanup refused: Database name [{$database}] does not contain an approved disposable test marker.");
        }
    }

    /**
     * Define the database environment for tests.
     *
     * @param  Application  $app
     */
    protected function defineEnvironment($app): void
    {
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
            ]);
            $app['config']->set('database.connections.source', [
                'driver' => 'mysql',
                'host' => getenv('DB_HOST') ?: '127.0.0.1',
                'port' => getenv('DB_PORT') ?: '3306',
                'database' => getenv('DB_DATABASE') ?: 'lifecycle_test',
                'username' => getenv('DB_USERNAME') ?: 'root',
                'password' => getenv('DB_PASSWORD') ?: '',
                'prefix' => 'src_',
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
            ]);
            $app['config']->set('database.connections.source', [
                'driver' => 'pgsql',
                'host' => getenv('DB_HOST') ?: '127.0.0.1',
                'port' => getenv('DB_PORT') ?: '5432',
                'database' => getenv('DB_DATABASE') ?: 'lifecycle_test',
                'username' => getenv('DB_USERNAME') ?: 'postgres',
                'password' => getenv('DB_PASSWORD') ?: '',
                'prefix' => 'src_',
            ]);
        } else {
            $app['config']->set('database.default', 'testing');
            $app['config']->set('database.connections.testing', [
                'driver' => 'sqlite',
                'database' => ':memory:',
                'prefix' => '',
            ]);
            $app['config']->set('database.connections.source', [
                'driver' => 'sqlite',
                'database' => ':memory:',
                'prefix' => '',
            ]);
        }

        $app['config']->set('database-lifecycle-suite.source_connection', 'source');
        $app['config']->set('database-lifecycle-suite.model_path', 'app/Models');
    }
}
