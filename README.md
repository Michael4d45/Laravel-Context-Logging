# Contextual Logging for Laravel

[![Latest Version on Packagist](https://img.shields.io/packagist/v/michael/context-logging.svg?style=flat-square)](https://packagist.org/packages/michael/context-logging)
[![Tests](https://img.shields.io/github/actions/workflow/status/michael/context-logging/run-tests.yml?branch=main&label=tests&style=flat-square)](https://github.com/michael/context-logging/actions?query=workflow%3Arun-tests+branch%3Amain)
[![Total Downloads](https://img.shields.io/packagist/dt/michael/context-logging.svg?style=flat-square)](https://packagist.org/packages/michael/context-logging)

This package provides a structured, context-first logging model for Laravel applications.

Instead of emitting many unstructured log lines during execution, the system accumulates contextual information throughout a request's lifecycle and emits **a single structured log event** at completion. The resulting log data is high-signal, query-friendly, and compatible with modern observability workflows.

**Inspired by** ["Logging sucks"](https://loggingsucks.com) - a comprehensive exploration of wide events and modern observability practices.

## Installation

You can install the package via composer:

```bash
composer require michael/context-logging
```

The package will automatically register itself thanks to Laravel's package discovery.

## How It Works

### Traditional Logging

```
Application code → Log::info() → formatter → handler → output
```

### Contextual Logging

```
Application code → Log::info() → in-memory context
Request termination → single structured log → Laravel logger → output
```

Log calls become **annotations**, not emissions.

## Usage

The package preserves the existing Laravel logging interface. No changes to your application code are required!

```php
<?php

namespace App\Http\Controllers;

use Illuminate\Support\Facades\Log;

class OrderController extends Controller
{
    public function store(Request $request)
    {
        Log::info('Order placed', ['order_id' => 123]);

        // More application logic...
        Log::info('Payment processed', ['amount' => 99.99]);

        // Even error conditions
        try {
            $this->processOrder();
        } catch (\Exception $e) {
            Log::error('Order processing failed', [
                'error' => $e->getMessage(),
                'order_id' => 123
            ]);
        }

        return response()->json(['status' => 'success']);
    }
}
```

Instead of emitting 3 separate log entries, this produces a single structured log:

```json
{
  "context": {
    "request_id": "550e8400-e29b-41d4-a716-446655440000",
    "method": "POST",
    "path": "orders",
    "user_id": 42,
    "status": 200,
    "duration_ms": 83.4
  },
  "events": [
    {
      "level": "info",
      "message": "Order placed",
      "context": {
        "order_id": 123
      }
    },
    {
      "level": "info",
      "message": "Payment processed",
      "context": {
        "amount": 99.99
      }
    },
    {
      "level": "error",
      "message": "Order processing failed",
      "context": {
        "error": "Payment gateway timeout",
        "order_id": 123
      }
    }
  ]
}
```

## Architecture

### Core Components

- **Context Store**: Accumulates request-wide metadata and log events
- **Contextual Logger**: Mirrors Laravel's logging API but accumulates instead of emitting
- **Log Facade**: Drop-in replacement for Laravel's `Log` facade
- **Request Context Middleware**: Seeds baseline request metadata early in the pipeline
- **Emit Middleware**: Emits the single structured log entry after request completion

### Request Lifecycle

1. Request enters application
2. Request context middleware populates base metadata (request ID, method, path, etc.)
3. Application code calls `Log::*()` as usual - these become context annotations
4. Request completes
5. Terminating middleware emits one structured log entry via Laravel's logger
6. Laravel handles delivery via its configured logging stack

## Configuration

The package works out of the box with no configuration required. However, you can publish the configuration file for customization:

```bash
php artisan vendor:publish --provider="Michael\\ContextLogging\\ContextLoggingServiceProvider" --tag=config
```

This will create `config/context-logging.php` with options for:

- Enabling/disabling the package
- Controlling which request fields are included
- Specifying correlation headers for distributed tracing
- Future sampling configuration

## Compatibility

The package emits exactly one log entry using Laravel's standard `Log::info()` method, ensuring full compatibility with:

- JSON formatters
- stdout / stderr output
- File-based logging
- OpenTelemetry exporters
- Vendor-specific Monolog handlers
- All existing Laravel logging configuration

## Performance

- All operations are in-memory until request termination
- No I/O during request execution
- One log write per request
- Minimal object allocation
- No impact on response time

## Operational Characteristics

- Safe to enable incrementally
- Easy to disable via configuration or environment variable
- Does not interfere with existing logs outside HTTP requests
- Predictable log volume (1 log per HTTP request with events)
- Works with queues, jobs, and other Laravel features

## Error Handling

Exceptions and errors enrich the existing request context rather than being emitted separately. This guarantees correlation without relying on log aggregation heuristics.

## Future Extensions

The design supports future extensions without API breakage:

- Queue/job lifecycle equivalents
- Tail-based sampling
- Trace/span correlation
- Context namespacing
- Field-level redaction

## Contributing

Please see [CONTRIBUTING](CONTRIBUTING.md) for details.

## License

The MIT License (MIT). Please see [License File](LICENSE.md) for more information.
