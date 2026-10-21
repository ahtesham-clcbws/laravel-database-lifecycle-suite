<?php

declare(strict_types=1);

namespace Clcbws\DatabaseLifecycleSuite\Tests;

use Clcbws\DatabaseLifecycleSuite\DatabaseLifecycleSuiteServiceProvider;
use Illuminate\Foundation\Application;
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
     */
    protected function cleanDatabase(): void
    {
        $defaultConn = (string) config('database.default', 'testing');
        if (in_array($defaultConn, ['mysql', 'pgsql'], true)) {
            try {
                Schema::connection($defaultConn)->dropAllTables();
                if (config('database.connections.source')) {
                    Schema::connection('source')->dropAllTables();
                }
            } catch (\Throwable) {
                // Ignore cleanup errors on fresh databases
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
