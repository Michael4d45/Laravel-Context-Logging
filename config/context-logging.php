<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Context Logging Configuration
    |--------------------------------------------------------------------------
    |
    | Configuration options for the contextual logging package.
    | Currently, this package operates with sensible defaults.
    |
    | Future options may include:
    | - Request field filtering
    | - Log level filtering
    | - Sampling rates
    | - Custom context enrichment
    |
    */

    'http' => [
        // Disable this to turn off outbound HTTP auto-capture and sub-context APIs.
        'enabled' => env('CONTEXT_LOG_HTTP_ENABLED', true),

        // Capture options for global outbound HTTP instrumentation.
        'capture_headers' => env('CONTEXT_LOG_HTTP_CAPTURE_HEADERS', false),
        'capture_body' => env('CONTEXT_LOG_HTTP_CAPTURE_BODY', false),

        // Header names to redact in custom hooks.
        'redact_headers' => [
            'authorization',
            'cookie',
            'set-cookie',
            'x-api-key',
        ],

        // Redaction token used for masked headers/body fields.
        'redact_value' => '[redacted]',

        // JSON body keys to redact recursively when capture_body is enabled.
        'redact_body_fields' => [
            'password',
            'token',
            'secret',
            'access_token',
            'refresh_token',
            'api_key',
            'client_secret',
        ],

        // Query string keys to redact recursively.
        'redact_query_params' => [
            'token',
            'access_token',
            'refresh_token',
            'api_key',
            'signature',
        ],

    ],

];