<?php

declare(strict_types=1);

namespace Clcbws\DatabaseLifecycleSuite\Tests;

use Orchestra\Testbench\TestCase as Orchestra;
use Clcbws\DatabaseLifecycleSuite\DatabaseLifecycleSuiteServiceProvider;

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
     * @param \Illuminate\Foundation\Application $app
     * @return array
     */
    protected function getPackageProviders($app): array
    {
        return [
            DatabaseLifecycleSuiteServiceProvider::class,
        ];
    }

    /**
     * Define the database environment for tests.
     *
     * @param \Illuminate\Foundation\Application $app
     * @return void
     */
    protected function defineEnvironment($app): void
    {
        // Use a shared memory database for SQLite to ensure visibility across Artisan commands
        $app['config']->set('database.default', 'testing');
        $app['config']->set('database.connections.testing', [
            'driver'   => 'sqlite',
            'database' => ':memory:',
            'prefix'   => '',
        ]);
        
        $app['config']->set('database.connections.source', [
            'driver'   => 'sqlite',
            'database' => ':memory:',
            'prefix'   => '',
        ]);

        $app['config']->set('database-lifecycle-suite.source_connection', 'source');
        $app['config']->set('database-lifecycle-suite.model_path', 'app/Models');
    }
}
