<?php

declare(strict_types=1);

namespace Clcbws\DatabaseLifecycleSuite;

use Illuminate\Support\ServiceProvider;
use Clcbws\DatabaseLifecycleSuite\Commands\StandardizeIndexesCommand;
use Clcbws\DatabaseLifecycleSuite\Commands\DataDriftCommand;
use Clcbws\DatabaseLifecycleSuite\Commands\LegacyBridgeCommand;
use Clcbws\DatabaseLifecycleSuite\Commands\IndexAuditCommand;
use Clcbws\DatabaseLifecycleSuite\Commands\ErdCommand;
use Clcbws\DatabaseLifecycleSuite\Commands\SnapshotCommand;
use Clcbws\DatabaseLifecycleSuite\Commands\LifecycleStatusCommand;
use Clcbws\DatabaseLifecycleSuite\Commands\SoftDeleteAuditCommand;
use Clcbws\DatabaseLifecycleSuite\Commands\TableSizeReportCommand;
use Clcbws\DatabaseLifecycleSuite\Commands\RedundantIndexCommand;
use Clcbws\DatabaseLifecycleSuite\Commands\PiiScanCommand;
use Clcbws\DatabaseLifecycleSuite\Commands\SnapshotDiffCommand;
use Clcbws\DatabaseLifecycleSuite\Commands\ColumnTypeAuditCommand;
use Clcbws\DatabaseLifecycleSuite\Commands\TableConventionAuditCommand;
use Clcbws\DatabaseLifecycleSuite\Commands\SnapshotRestoreCommand;
use Clcbws\DatabaseLifecycleSuite\Commands\QueryExplainCommand;
use Clcbws\DatabaseLifecycleSuite\Commands\ConnectionCheckCommand;
use Clcbws\DatabaseLifecycleSuite\Commands\CastAuditCommand;

class DatabaseLifecycleSuiteServiceProvider extends ServiceProvider
{
    /**
     * Register the application services.
     *
     * @return void
     */
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__ . '/../config/database-lifecycle-suite.php', 'database-lifecycle-suite');
    }

    /**
     * Bootstrap the application services.
     *
     * @return void
     */
    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->publishes([
                __DIR__ . '/../config/database-lifecycle-suite.php' => \config_path('database-lifecycle-suite.php'),
            ], 'config');

            $this->commands([
                StandardizeIndexesCommand::class,
                DataDriftCommand::class,
                LegacyBridgeCommand::class,
                IndexAuditCommand::class,
                ErdCommand::class,
                SnapshotCommand::class,
                LifecycleStatusCommand::class,
                SoftDeleteAuditCommand::class,
                TableSizeReportCommand::class,
                RedundantIndexCommand::class,
                PiiScanCommand::class,
                SnapshotDiffCommand::class,
                ColumnTypeAuditCommand::class,
                TableConventionAuditCommand::class,
                SnapshotRestoreCommand::class,
                QueryExplainCommand::class,
                ConnectionCheckCommand::class,
                CastAuditCommand::class,
            ]);
        }
    }
}
