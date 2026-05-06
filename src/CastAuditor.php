<?php

declare(strict_types=1);

namespace Clcbws\DatabaseLifecycleSuite;

use Illuminate\Support\Facades\Schema;
use ReflectionClass;

/**
 * Class CastAuditor
 * 
 * Verifies that Eloquent Model $casts match the actual database column types.
 * Helps identify runtime type mismatches and potential data corruption.
 */
readonly class CastAuditor
{
    /**
     * Audit model casts against DB column types.
     *
     * @param string $modelPath Directory path where models are located.
     * @param string|null $connection The database connection to use.
     * @return array List of detected cast mismatches.
     */
    public function audit(string $modelPath, ?string $connection = null): array
    {
        $mismatches = [];
        
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

                $instance = $reflection->newInstanceWithoutConstructor();
                
                if (!$instance instanceof \Illuminate\Database\Eloquent\Model) {
                    continue;
                }

                $casts = $instance->getCasts();
                $table = $instance->getTable();

                if (!Schema::connection($connection)->hasTable($table)) {
                    continue;
                }

                $columns = \collect(Schema::connection($connection)->getColumns($table))->keyBy('name');

                foreach ($casts as $column => $castType) {
                    if (!$columns->has($column)) {
                        continue;
                    }

                    $dbType = strtolower($columns[$column]['type_name']);
                    if (!$this->isCastCompatible($castType, $dbType)) {
                        $mismatches[] = [
                            'model' => $className,
                            'column' => $column,
                            'cast' => $castType,
                            'db_type' => $dbType,
                        ];
                    }
                }
            } catch (\Exception $e) {
                continue;
            }
        }

        return $mismatches;
    }

    /**
     * Determine if a cast type is compatible with a database column type.
     *
     * @param string $cast
     * @param string $dbType
     * @return bool
     */
    protected function isCastCompatible(string $cast, string $dbType): bool
    {
        $cast = strtolower($cast);
        
        return match ($cast) {
            'int', 'integer' => str_contains($dbType, 'int'),
            'bool', 'boolean' => str_contains($dbType, 'bool') || str_contains($dbType, 'tinyint(1)') || str_contains($dbType, 'bit'),
            'float', 'double', 'decimal' => str_contains($dbType, 'float') || str_contains($dbType, 'double') || str_contains($dbType, 'decimal') || str_contains($dbType, 'numeric'),
            'date', 'datetime', 'timestamp' => str_contains($dbType, 'date') || str_contains($dbType, 'time') || str_contains($dbType, 'timestamp'),
            'json', 'array', 'object' => str_contains($dbType, 'json') || str_contains($dbType, 'text'),
            default => true,
        };
    }
}
