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
     */
    protected function mapType(string $nativeType): string
    {
        $nativeType = strtolower($nativeType);

        return match (true) {
            str_contains($nativeType, 'tinyint(1)') || $nativeType === 'bool' || $nativeType === 'boolean' => 'boolean',
            str_contains($nativeType, 'bigint') => 'bigInteger',
            str_contains($nativeType, 'tinyint') => 'tinyInteger',
            str_contains($nativeType, 'smallint') => 'smallInteger',
            str_contains($nativeType, 'mediumint') => 'mediumInteger',
            str_contains($nativeType, 'int') => 'integer',
            str_contains($nativeType, 'longtext') => 'longText',
            str_contains($nativeType, 'mediumtext') => 'mediumText',
            str_contains($nativeType, 'text') => 'text',
            str_contains($nativeType, 'varchar') || str_contains($nativeType, 'char') => 'string',
            str_contains($nativeType, 'decimal') || str_contains($nativeType, 'numeric') => 'decimal',
            str_contains($nativeType, 'float') => 'float',
            str_contains($nativeType, 'double') || str_contains($nativeType, 'real') => 'double',
            str_contains($nativeType, 'datetime') => 'dateTime',
            str_contains($nativeType, 'timestamp') => 'timestamp',
            str_contains($nativeType, 'date') => 'date',
            str_contains($nativeType, 'time') => 'time',
            str_contains($nativeType, 'year') => 'year',
            str_contains($nativeType, 'json') => 'json',
            str_contains($nativeType, 'blob') || str_contains($nativeType, 'binary') || str_contains($nativeType, 'bytea') => 'binary',
            str_contains($nativeType, 'uuid') => 'uuid',
            str_contains($nativeType, 'ipaddress') || str_contains($nativeType, 'inet') => 'ipAddress',
            str_contains($nativeType, 'macaddress') || str_contains($nativeType, 'macaddr') => 'macAddress',
            default => 'string',
        };
    }

    /**
     * Check if a native column type is supported for faithful restoration.
     */
    protected function isSupportedDatabaseType(string $nativeType): bool
    {
        $nativeType = strtolower(trim($nativeType));
        if ($nativeType === '') {
            return false;
        }

        $supportedSubstrings = [
            'int', 'integer', 'tinyint', 'smallint', 'mediumint', 'bigint',
            'varchar', 'char', 'string', 'text', 'tinytext', 'mediumtext', 'longtext',
            'bool', 'boolean', 'decimal', 'numeric', 'float', 'double', 'real',
            'date', 'datetime', 'timestamp', 'time', 'year',
            'json', 'jsonb', 'blob', 'binary', 'varbinary', 'bytea', 'uuid',
            'inet', 'ipaddress', 'macaddr', 'macaddress',
        ];

        foreach ($supportedSubstrings as $sub) {
            if (str_contains($nativeType, $sub)) {
                return true;
            }
        }

        return false;
    }
}
