<?php

declare(strict_types=1);

namespace Clcbws\DatabaseLifecycleSuite\Traits;

/**
 * Trait MapsDatabaseTypes
 * 
 * Provides consistent mapping between native database types and Laravel 
 * migration/schema types.
 */
trait MapsDatabaseTypes
{
    /**
     * Map a native database type to a Laravel migration method name.
     *
     * @param string $nativeType
     * @return string
     */
    protected function mapType(string $nativeType): string
    {
        $nativeType = strtolower($nativeType);

        return match (true) {
            str_contains($nativeType, 'char') => 'string',
            str_contains($nativeType, 'text') => 'text',
            str_contains($nativeType, 'bigint') => 'bigInteger',
            str_contains($nativeType, 'int') => 'integer',
            str_contains($nativeType, 'bool') || str_contains($nativeType, 'tinyint(1)') => 'boolean',
            str_contains($nativeType, 'decimal') => 'decimal',
            str_contains($nativeType, 'float') => 'float',
            str_contains($nativeType, 'double') => 'double',
            str_contains($nativeType, 'date') && !str_contains($nativeType, 'time') => 'date',
            str_contains($nativeType, 'datetime') || str_contains($nativeType, 'timestamp') => 'timestamp',
            str_contains($nativeType, 'time') => 'time',
            str_contains($nativeType, 'json') => 'json',
            str_contains($nativeType, 'blob') => 'binary',
            default => 'string',
        };
    }
}
