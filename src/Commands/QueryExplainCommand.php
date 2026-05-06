<?php

declare(strict_types=1);

namespace Clcbws\DatabaseLifecycleSuite\Commands;

use Illuminate\Console\Command;
use Clcbws\DatabaseLifecycleSuite\QueryAnalyzer;

/**
 * Class QueryExplainCommand
 * 
 * Artisan command to explain SQL queries and get performance insights.
 */
class QueryExplainCommand extends Command
{
    /**
     * @var string
     */
    protected $signature = 'db:explain {query : The SQL SELECT query to analyze} {--database= : The database connection to use}';

    /**
     * @var string
     */
    protected $description = 'Analyze a SQL SELECT query performance using EXPLAIN';

    /**
     * Execute the console command.
     *
     * @param QueryAnalyzer $analyzer
     * @return int
     */
    public function handle(QueryAnalyzer $analyzer): int
    {
        $query = $this->argument('query');
        $connection = $this->option('database');

        $this->info("🔬 Analyzing query" . ($connection ? " in [{$connection}]" : "") . "...");

        try {
            $report = $analyzer->analyze((string) $query, $connection);

            $this->comment("\nEXPLAIN Results ({$report['driver']}):");
            
            // Format rows for display
            $rows = array_map(function ($row) {
                return (array) $row;
            }, $report['rows']);
            
            if (!empty($rows)) {
                $this->table(array_keys($rows[0]), $rows);
            }

            if (!empty($report['insights'])) {
                $this->warn("\n💡 Performance Insights:");
                foreach ($report['insights'] as $insight) {
                    $this->line("- {$insight}");
                }
            } else {
                $this->info("\n✅ No obvious performance issues detected.");
            }

        } catch (\Exception $e) {
            $this->error($e->getMessage());
            return 1;
        }

        return 0;
    }
}
