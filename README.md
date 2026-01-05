# Contextual Logging for Laravel

[![Latest Version on Packagist](https://img.shields.io/packagist/v/michael4d45/context-logging.svg?style=flat-square)](https://packagist.org/packages/michael4d45/context-logging)
[![Tests](https://img.shields.io/github/actions/workflow/status/Michael4d45/Context-Logging/run-tests.yml?branch=main&label=tests&style=flat-square)](https://github.com/Michael4d45/Context-Logging/actions?query=workflow%3Arun-tests+branch%3Amain)
[![Total Downloads](https://img.shields.io/packagist/dt/michael4d45/context-logging.svg?style=flat-square)](https://packagist.org/packages/michael4d45/context-logging)

This package provides a structured, context-first logging model for Laravel applications.

Instead of emitting many unstructured log lines during execution, the system accumulates contextual information throughout a request's lifecycle and emits **a single structured log event** at completion. The resulting log data is high-signal, query-friendly, and compatible with modern observability workflows.

**Inspired by** ["Logging sucks"](https://loggingsucks.com) - a comprehensive exploration of wide events and modern observability practices.

## Installation

You can install the package via composer:

```bash
composer require michael4d45/context-logging
```

## Laravel Middleware Registration

This package requires two global HTTP middleware to function correctly:

* One to initialize request-level context
* One to emit a single structured log entry after the request completes

Laravel 11 and recent Laravel 10 versions register middleware via `bootstrap/app.php`.

### Registering Global Middleware

Open `bootstrap/app.php` and locate the `withMiddleware` section.

Append the package middleware to the global stack:

```php
use Illuminate\Foundation\Configuration\Middleware;
use Michael4d45\ContextLogging\Middleware\RequestContextMiddleware;
use Michael4d45\ContextLogging\Middleware\EmitContextMiddleware;

->withMiddleware(function (Middleware $middleware): void {
    $middleware->append(RequestContextMiddleware::class);
    $middleware->append(EmitContextMiddleware::class);
})
```

* `RequestContextMiddleware` runs early and seeds request metadata.
* `EmitContextMiddleware` implements both `handle` and `terminate` methods, ensuring it runs during request processing and emits the structured log entry after the response is sent.

Both middleware are appended to run after Laravel's core request processing.

### Ordering Considerations

Middleware execution order matters.

Recommended placement:

* `RequestContextMiddleware` **after** request normalization middleware (e.g. trimming, proxy handling)
* `EmitContextMiddleware` **at the end** of the global stack

Using `append()` achieves this safely.

If you need the request context earlier, you may use `prepend()` instead:

```php
$middleware->prepend(RequestContextMiddleware::class);
```

### Manually Managing the Global Middleware Stack (Optional)

If your application explicitly defines Laravel's global middleware stack using `use()`, include the package middleware in that list.

Example:

```php
use Illuminate\Foundation\Configuration\Middleware;
use Michael4d45\ContextLogging\Middleware\RequestContextMiddleware;
use Michael4d45\ContextLogging\Middleware\EmitContextMiddleware;

->withMiddleware(function (Middleware $middleware): void {
    $middleware->use([
        \Illuminate\Foundation\Http\Middleware\InvokeDeferredCallbacks::class,
        \Illuminate\Http\Middleware\TrustProxies::class,
        \Illuminate\Http\Middleware\HandleCors::class,
        \Illuminate\Foundation\Http\Middleware\PreventRequestsDuringMaintenance::class,
        \Illuminate\Http\Middleware\ValidatePostSize::class,
        \Illuminate\Foundation\Http\Middleware\TrimStrings::class,
        \Illuminate\Foundation\Http\Middleware\ConvertEmptyStringsToNull::class,

        // Contextual logging
        RequestContextMiddleware::class,
        EmitContextMiddleware::class,
    ]);
})
```

When using `use()`, Laravel **does not** automatically include defaults—you are responsible for the full stack. Both middleware should be included in the array.

### Notes

* Middleware registration is intentionally **not** automatic.
* This avoids surprising behavior and allows explicit control.
* No changes to route middleware or middleware groups are required.

### Summary

To enable contextual logging in Laravel:

1. Install the package via Composer
2. Register the two global middleware in `bootstrap/app.php`
3. Continue using `Log::info()`, `Log::error()`, etc. as usual

Once enabled, the package will emit **one structured log event per request** using Laravel's existing logging configuration.

## How It Works

### Traditional Logging

```
Application code → Log::info() → formatter → handler → output
```

### Contextual Logging

```
Application code → Log::info() → in-memory context only
Request termination → single wide event → Laravel logger → output
```

Log calls become **annotations** that are collected into comprehensive wide events.

## Philosophy: Wide Events Only

This package embraces **Wide Events** exclusively - no more scattered logs! Instead of emitting individual log entries throughout request processing, all logging calls are accumulated and emitted as a single comprehensive event at request completion.

**Why Wide Events?**
- **Complete context**: Every log call from the entire request in one place
- **Request metadata**: Method, path, duration, status, user info
- **Temporal ordering**: All events with precise timestamps
- **Query-friendly**: Single event per request for analysis
- **Clean logs**: No scattered entries to grep through

**The transformation:**
- ❌ **Before**: Dozens of individual log lines per request
- ✅ **After**: One structured event containing everything

Your logs stop lying to you. They start telling the whole truth.

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

Instead of emitting 3 separate log entries, this produces a single wide event:

```json
{
  "message": "Request completed",
  "context": {
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
        },
        "timestamp": 1767636443.123604
      },
      {
        "level": "info",
        "message": "Payment processed",
        "context": {
          "amount": 99.99
        },
        "timestamp": 1767636443.123730
      },
      {
        "level": "error",
        "message": "Order processing failed",
        "context": {
          "error": "Payment gateway timeout",
          "order_id": 123
        },
        "timestamp": 1767636443.296303
      }
    ]
  },
  "level": 200,
  "level_name": "INFO",
  "channel": "local",
  "datetime": "2026-01-05T18:07:23.389088+00:00",
  "extra": {}
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

The package works out of the box with no configuration required. It provides wide events exclusively - no individual log pass-through.

You can optionally publish the configuration file for future customization:

```bash
php artisan vendor:publish --provider="Michael4d45\\ContextLogging\\ContextLoggingServiceProvider" --tag=config
```

This creates `config/context-logging.php` for future options like sampling rates, field filtering, and custom context enrichment.

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
