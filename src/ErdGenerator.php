<?php

declare(strict_types=1);

namespace Clcbws\DatabaseLifecycleSuite;

use Illuminate\Support\Facades\Schema;
use Clcbws\DatabaseLifecycleSuite\Traits\FiltersTables;

/**
 * Class ErdGenerator
 * 
 * Generates visual Entity Relationship Diagrams (ERD) in Mermaid.js format 
 * by analyzing the database schema.
 */
readonly class ErdGenerator
{
    use FiltersTables;
    /**
     * Generate Mermaid.js graph syntax for the database schema.
     *
     * @param string|null $connection The database connection to use.
     * @return string Mermaid-compatible graph definition.
     */
    public function generateMermaid(?string $connection = null): string
    {
        $tables = $this->getFilteredTables($connection);
        $mermaid = "erDiagram\n";

        foreach ($tables as $table) {
            $tableName = $table['name'];
            $safeTable = str_replace(' ', '_', $tableName);
            
            $mermaid .= "    {$safeTable} {\n";

            $columns = Schema::connection($connection)->getColumns($tableName);
            foreach ($columns as $column) {
                $type = (string) $column['type_name'];
                $cleanType = (string) preg_replace('/[^a-zA-Z0-9]/', '', $type);
                $mermaid .= "        {$cleanType} {$column['name']}\n";
            }

            $mermaid .= "    }\n";

            $foreignKeys = Schema::connection($connection)->getForeignKeys($tableName);
            foreach ($foreignKeys as $fk) {
                $target = str_replace(' ', '_', $fk['foreign_table']);
                $mermaid .= "    {$safeTable} }o--|| {$target} : \"\"\n";
            }
        }

        return $mermaid;
    }
}
