<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Context Logging Configuration
    |--------------------------------------------------------------------------
    |
    | Configuration options for the contextual logging package.
    |
    */

    'http' => [
        // Disable this to turn off outbound HTTP auto-capture and sub-context APIs.
        'enabled' => env('CONTEXT_LOG_HTTP_ENABLED', false),

        // Transparent GuzzleHttp\Client constructor patch (sidecar / zero app changes).
        // The Composer plugin re-applies this on every dump-autoload when allow-plugins
        // includes michael4d45/context-logging. Falls back to Http::globalMiddleware if
        // the patch is missing. When the patch is active, facade middleware is skipped.
        'guzzle_patch' => env('CONTEXT_LOG_HTTP_GUZZLE_PATCH', false),

        // Bind GuzzleHttp\Client in the container to an instrumented factory (DI only; does not
        // affect `new Client()` — use guzzle_patch for that).
        'guzzle_binding' => env('CONTEXT_LOG_HTTP_GUZZLE_BINDING', false),

        // Capture options for global outbound HTTP instrumentation.
        'capture_headers' => env('CONTEXT_LOG_HTTP_CAPTURE_HEADERS', false),
        'capture_body' => env('CONTEXT_LOG_HTTP_CAPTURE_BODY', false),

        // Header names to redact in custom hooks and request/response logging.
        'redact_headers' => [
            'authorization',
            'cookie',
            'set-cookie',
            'x-api-key',
        ],

        // Redaction token used for masked headers/body fields.
        'redact_value' => '[redacted]',

        // JSON body keys to redact recursively when capture_body or request/response logging is enabled.
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

    /*
    |--------------------------------------------------------------------------
    | Incoming Request / Response Logging
    |--------------------------------------------------------------------------
    |
    | When enabled, request and response details are added as events to the
    | context log (only for web requests; ignored in console). Incoming Request
    | is always added when log.request is true (empty body/query keys omitted).
    | Response and user logging run in terminating middleware.
    |
    */

    'log' => [
        'console' => env('CONTEXT_LOG_CONSOLE', false),
        'request' => env('CONTEXT_LOG_REQUEST', false),
        'response' => env('CONTEXT_LOG_RESPONSE', false),
        'user' => env('CONTEXT_LOG_USER', false),

        // User model attributes to include in the User log event when log.user is true. Use 'id' for the model key (getKey()).
        'user_attributes' => ['id', 'name', 'email'],

        'db' => env('CONTEXT_LOG_DB', false),
        'cache' => env('CONTEXT_LOG_CACHE', false),
        'queue' => env('CONTEXT_LOG_QUEUE', false),
        'mail' => env('CONTEXT_LOG_MAIL', false),
        'reverb' => env('CONTEXT_LOG_REVERB', false),
        'schedule' => env('CONTEXT_LOG_SCHEDULE', false),
        'notifications' => env('CONTEXT_LOG_NOTIFICATIONS', false),
        'broadcasting' => env('CONTEXT_LOG_BROADCASTING', false),

        // Route names or path patterns to exclude from request/response logging (e.g. 'health', 'horizon.*', 'livewire/*').
        'ignore_routes' => array_filter(explode(',', env('CONTEXT_LOG_IGNORE_ROUTES', ''))),
    ],

    /*
    |--------------------------------------------------------------------------
    | Console Command Context
    |--------------------------------------------------------------------------
    |
    | Commands listed here do not get wrapped in a single "Console run completed"
    | context log. Use for long-running workers (e.g. queue:work, horizon) so
    | each job/unit of work is logged separately instead of one giant entry.
    | Supports Laravel wildcards (e.g. 'queue:*', 'horizon'). Tinker is always
    | skipped from command-wide wrapping so it can emit once per evaluation.
    |
    */

    'console' => [
        'skip_commands' => array_filter(array_merge(
            ['queue:work', 'queue:listen'],
            explode(',', env('CONTEXT_LOG_SKIP_COMMANDS', '')),
        )),
    ],

    /*
    |--------------------------------------------------------------------------
    | log:monitor Display
    |--------------------------------------------------------------------------
    |
    | Character limits for request/response bodies shown by the log:monitor
    | command. 0 = no limit. CLI options --request-body-limit and
    | --response-body-limit override these values when provided.
    |
    | When request_body_limit_only / response_body_limit_only flags are enabled,
    | the corresponding body limit is applied only to bodies that start with
    | <!DOCTYPE html> or { (JSON object). If both flags are false, the limit
    | applies to all bodies whenever the limit is greater than 0.
    |
    */

    'monitor' => [
        'request_body_limit' => (int) env('CONTEXT_LOG_MONITOR_REQUEST_BODY_LIMIT', 0),
        'response_body_limit' => (int) env('CONTEXT_LOG_MONITOR_RESPONSE_BODY_LIMIT', 0),

        'request_body_limit_only' => [
            'doctype_html' => filter_var(env('CONTEXT_LOG_MONITOR_REQUEST_LIMIT_DOCTYPE_HTML', false), FILTER_VALIDATE_BOOL),
            'json_object' => filter_var(env('CONTEXT_LOG_MONITOR_REQUEST_LIMIT_JSON_OBJECT', false), FILTER_VALIDATE_BOOL),
        ],

        'response_body_limit_only' => [
            'doctype_html' => filter_var(env('CONTEXT_LOG_MONITOR_RESPONSE_LIMIT_DOCTYPE_HTML', false), FILTER_VALIDATE_BOOL),
            'json_object' => filter_var(env('CONTEXT_LOG_MONITOR_RESPONSE_LIMIT_JSON_OBJECT', false), FILTER_VALIDATE_BOOL),
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Profiling
    |--------------------------------------------------------------------------
    |
    | Local-dev oriented: attach collapsed call-site traces on Log::* events
    | (same shape as SQL events) and correlate active native profilers onto
    | the wide-event outer context as join keys (not flamegraph payloads).
    |
    */

    'profiling' => [
        'log_traces' => filter_var(env('CONTEXT_LOG_PROFILING_LOG_TRACES', true), FILTER_VALIDATE_BOOL),
        'log_trace_min_level' => env('CONTEXT_LOG_PROFILING_LOG_TRACE_MIN_LEVEL', 'debug'),
        'correlate' => filter_var(env('CONTEXT_LOG_PROFILING_CORRELATE', true), FILTER_VALIDATE_BOOL),
        'adapters' => ['spx', 'xdebug', 'blackfire'],
        'spx' => [
            'ui_base_url' => env('CONTEXT_LOG_SPX_UI_BASE_URL', ''),
        ],
    ],

];