<?php

declare(strict_types=1);

namespace Clcbws\DatabaseLifecycleSuite;

use Clcbws\DatabaseLifecycleSuite\Traits\FiltersTables;
use Illuminate\Support\Facades\Schema;

/**
 * Class ErdGenerator
 *
 * Generates valid, safely-escaped Entity Relationship Diagrams (ERD)
 * in Mermaid.js format from the database schema.
 */
readonly class ErdGenerator
{
    use FiltersTables;

    /**
     * Generate Mermaid.js graph syntax for the database schema.
     *
     * @param  string|null  $connection  The database connection to use.
     * @return string Mermaid-compatible graph definition.
     */
    public function generateMermaid(?string $connection = null): string
    {
        $tables = $this->getFilteredTables($connection);
        $mermaid = "erDiagram\n";

        $relationships = [];

        foreach ($tables as $table) {
            $tableName = (string) $table['name'];
            $safeTable = $this->sanitizeIdentifier($tableName);

            $mermaid .= "    {$safeTable} {\n";

            $columns = Schema::connection($connection)->getColumns($tableName);
            foreach ($columns as $column) {
                $rawType = (string) $column['type_name'];
                $cleanType = (string) preg_replace('/[^a-zA-Z0-9_]/', '', $rawType);
                if ($cleanType === '') {
                    $cleanType = 'string';
                }

                $cleanCol = $this->sanitizeIdentifier((string) $column['name']);
                $mermaid .= "        {$cleanType} {$cleanCol}\n";
            }

            $mermaid .= "    }\n";

            $foreignKeys = Schema::connection($connection)->getForeignKeys($tableName);
            foreach ($foreignKeys as $fk) {
                $targetTable = (string) ($fk['foreign_table'] ?? '');
                if ($targetTable !== '') {
                    $safeTarget = $this->sanitizeIdentifier($targetTable);
                    $fkCol = $this->sanitizeIdentifier((string) (($fk['columns'][0] ?? 'fk')));
                    $relKey = "{$safeTable}->{$safeTarget}:{$fkCol}";
                    if (! isset($relationships[$relKey])) {
                        $relationships[$relKey] = "    {$safeTable} }o--|| {$safeTarget} : \"{$fkCol}\"\n";
                    }
                }
            }
        }

        foreach ($relationships as $line) {
            $mermaid .= $line;
        }

        return $mermaid;
    }

    /**
     * Sanitize table or column name into a valid Mermaid identifier.
     */
    protected function sanitizeIdentifier(string $name): string
    {
        $safe = (string) preg_replace('/[^a-zA-Z0-9_]/', '_', $name);
        if (preg_match('/^[0-9]/', $safe)) {
            $safe = 't_'.$safe;
        }

        return $safe ?: 'entity';
    }
}
