<?php

declare(strict_types=1);

namespace Clcbws\DatabaseLifecycleSuite\Traits;

use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

/**
 * Trait FiltersTables
 * 
 * Ensures that Schema::getTables() results are scoped to the current 
 * connection's database.
 */
trait FiltersTables
{
    /**
     * Get tables for the current connection, filtered by schema.
     *
     * @param string|null $connection
     * @return array
     */
    protected function getFilteredTables(?string $connection = null): array
    {
        $dbName = DB::connection($connection)->getDatabaseName();
        
        return \collect(Schema::connection($connection)->getTables())
            ->filter(fn($table) => ($table['schema'] ?? null) === $dbName)
            ->values()
            ->toArray();
    }
}
