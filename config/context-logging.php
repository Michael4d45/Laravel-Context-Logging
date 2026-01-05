<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Contextual Logging Configuration
    |--------------------------------------------------------------------------
    |
    | This file contains configuration options for the contextual logging package.
    | All settings are optional and have sensible defaults.
    |
    */

    /*
    |--------------------------------------------------------------------------
    | Enabled
    |--------------------------------------------------------------------------
    |
    | Whether contextual logging is enabled. When disabled, the package
    | behaves as a pass-through to Laravel's standard logging.
    |
    */
    'enabled' => env('CONTEXT_LOGGING_ENABLED', true),

    /*
    |--------------------------------------------------------------------------
    | Request Context Fields
    |--------------------------------------------------------------------------
    |
    | Which request context fields to include in the structured log.
    | Set to null to include all available fields.
    |
    */
    'request_fields' => [
        'request_id',
        'method',
        'path',
        'full_url',
        'ip',
        'user_agent',
        'user_id',
        'timestamp',
        'status',
        'duration_ms',
    ],

    /*
    |--------------------------------------------------------------------------
    | Correlation Headers
    |--------------------------------------------------------------------------
    |
    | HTTP headers to include in the context for distributed tracing correlation.
    |
    */
    'correlation_headers' => [
        'x-request-id',
        'x-correlation-id',
        'x-trace-id',
        'x-span-id',
    ],

    /*
    |--------------------------------------------------------------------------
    | Sampling
    |--------------------------------------------------------------------------
    |
    | Future: Sampling configuration for high-volume scenarios.
    | Currently not implemented.
    |
    */
    'sampling' => [
        'enabled' => false,
        'rate' => 1.0, // 1.0 = 100% sampling
    ],

];
