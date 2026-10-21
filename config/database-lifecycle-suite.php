<?php

declare(strict_types=1);

return [
    /*
    |--------------------------------------------------------------------------
    | Index Naming Standards
    |--------------------------------------------------------------------------
    |
    | Defines the standard naming pattern for database indexes.
    | Placeholders: {table}, {column}, {type} (index or unique).
    | Default: {table}_{column}_{type}
    |
    */
    'index_naming_pattern' => env('DB_LIFECYCLE_INDEX_PATTERN', '{table}_{column}_{type}'),

    /*
    |--------------------------------------------------------------------------
    | Data Drift Auditing
    |--------------------------------------------------------------------------
    |
    | Define which reference tables should be audited for data drift and the
    | source of truth connection to compare against.
    |
    */
    'source_connection' => env('DB_LIFECYCLE_SOURCE_CONNECTION', 'production'),

    'data_audit_tables' => [
        // 'roles',
        // 'permissions',
        // 'settings',
    ],

    /*
    |--------------------------------------------------------------------------
    | Model Directory Path
    |--------------------------------------------------------------------------
    |
    | Defines where your Eloquent models are located for cast and soft-delete audits.
    |
    */
    'model_path' => env('DB_LIFECYCLE_MODEL_PATH', 'app/Models'),

    /*
    |--------------------------------------------------------------------------
    | Heuristic PII Scanning Patterns
    |--------------------------------------------------------------------------
    |
    | Regular expressions used to identify potential PII column names for
    | compliance reporting (GDPR, CCPA). These are heuristic indicators only.
    |
    */
    'pii_patterns' => [
        'email' => '/email|mail/i',
        'phone' => '/phone|mobile|tel/i',
        'address' => '/address|street|city|zip|postal|country/i',
        'personal_id' => '/ssn|passport|national_id|tax_id|identity/i',
        'financial' => '/card|credit|debit|cvv|account_num|iban|swift/i',
        'dob' => '/dob|birth|birthday/i',
        'name' => '/first_name|last_name|surname|full_name/i',
        'auth' => '/password|secret|token|apikey/i',
    ],
];
