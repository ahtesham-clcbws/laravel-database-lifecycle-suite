<?php

declare(strict_types=1);

namespace Clcbws\DatabaseLifecycleSuite\Commands;

use Illuminate\Console\Command;
use Clcbws\DatabaseLifecycleSuite\ErdGenerator;

/**
 * Class ErdCommand
 * 
 * Artisan command to generate a Mermaid.js Entity Relationship Diagram (ERD).
 */
class ErdCommand extends Command
{
    /**
     * @var string
     */
    protected $signature = 'db:erd {--database= : The database connection to use} {--output= : Path to save the Mermaid file}';

    /**
     * @var string
     */
    protected $description = 'Generate a Mermaid.js Entity Relationship Diagram (ERD) of the database';

    /**
     * Execute the console command.
     *
     * @param ErdGenerator $generator
     * @return int
     */
    public function handle(ErdGenerator $generator): int
    {
        $connection = $this->option('database');
        $outputFile = $this->option('output');

        $this->info("🎨 Generating Entity Relationship Diagram" . ($connection ? " for [{$connection}]" : "") . "...");

        $mermaid = $generator->generateMermaid($connection);

        if ($outputFile) {
            file_put_contents((string) $outputFile, $mermaid);
            $this->info("✅ ERD saved to: {$outputFile}");
        } else {
            $this->line("\n" . $mermaid);
            $this->comment("\nTip: Copy the syntax above into https://mermaid.live to visualize your database.");
        }

        return 0;
    }
}
