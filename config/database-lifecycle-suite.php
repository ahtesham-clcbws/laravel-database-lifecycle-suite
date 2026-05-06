<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Index Naming Standards
    |--------------------------------------------------------------------------
    |
    | Define the naming convention for database indexes.
    | Default: {table}_{column}_{type}
    |
    */
    'index_naming_pattern' => '{table}_{column}_{type}',

    /*
    |--------------------------------------------------------------------------
    | Data Drift Auditing
    |--------------------------------------------------------------------------
    |
    | Define which tables should be audited for data drift and the source
    | connection to compare against.
    |
    */
    'source_connection' => 'production',
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
    | Define where your Eloquent models are located for auditing.
    |
    */
    'model_path' => 'app/Models',

    /*
    |--------------------------------------------------------------------------
    | PII Scanning Patterns
    |--------------------------------------------------------------------------
    |
    | Regular expressions used to identify potential PII columns.
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
