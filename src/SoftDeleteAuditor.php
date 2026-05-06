<?php

declare(strict_types=1);

namespace Clcbws\DatabaseLifecycleSuite;

use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Eloquent\SoftDeletes;
use ReflectionClass;
use Exception;

/**
 * Class SoftDeleteAuditor
 * 
 * Audits the consistency between Eloquent Models and the Database regarding 
 * Soft Deletes, supporting custom DELETED_AT column names.
 */
readonly class SoftDeleteAuditor
{
    /**
     * Audit models for soft delete consistency.
     *
     * @param string $modelPath Directory path where models are located.
     * @param string|null $connection The database connection to use.
     * @return array List of inconsistencies found.
     */
    public function audit(string $modelPath, ?string $connection = null): array
    {
        $inconsistencies = [];
        
        if (!is_dir($modelPath)) {
            return [];
        }

        $files = scandir($modelPath);
        foreach ($files as $file) {
            if ($file === '.' || $file === '..' || !str_ends_with($file, '.php')) {
                continue;
            }

            $className = 'App\\Models\\' . str_replace('.php', '', $file);
            
            if (!class_exists($className)) {
                continue;
            }

            try {
                $reflection = new ReflectionClass($className);
                if ($reflection->isAbstract()) {
                    continue;
                }

                $hasTrait = in_array(SoftDeletes::class, $reflection->getTraitNames());
                
                $instance = $reflection->newInstanceWithoutConstructor();
                $table = $instance->getTable();
                
                // Handle custom DELETED_AT column name if constant exists
                $deletedAtColumn = (string) ($reflection->hasConstant('DELETED_AT') 
                    ? $reflection->getConstant('DELETED_AT') 
                    : 'deleted_at');
                
                if (!Schema::connection($connection)->hasTable($table)) {
                    continue;
                }

                $hasColumn = Schema::connection($connection)->hasColumn($table, $deletedAtColumn);

                if ($hasTrait && !$hasColumn) {
                    $inconsistencies[] = [
                        'model' => $className,
                        'table' => $table,
                        'issue' => "Model uses SoftDeletes trait but table is missing [{$deletedAtColumn}] column.",
                    ];
                } elseif (!$hasTrait && $hasColumn) {
                    $inconsistencies[] = [
                        'model' => $className,
                        'table' => $table,
                        'issue' => "Table has [{$deletedAtColumn}] column but Model is missing SoftDeletes trait.",
                    ];
                }
            } catch (Exception $e) {
                // Skip if model cannot be instantiated or reflected
                continue;
            }
        }

        return $inconsistencies;
    }
}
