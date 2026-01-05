<?php

namespace Michael4d45\ContextLogging\Middleware;

use Closure;
use Illuminate\Http\Request;
use Michael4d45\ContextLogging\ContextStore;
use Illuminate\Support\Str;

/**
 * Request Context Middleware (Ingress).
 *
 * Seeds baseline request metadata and establishes correlation identifiers.
 * Executes early in the middleware stack to populate foundational context.
 */
class RequestContextMiddleware
{
    public function __construct(
        protected ContextStore $contextStore
    ) {}

    /**
     * Handle an incoming request.
     */
    public function handle(Request $request, Closure $next)
    {
        // Initialize the context store for this request
        $this->contextStore->initialize();

        // Generate a unique request ID if not already present
        $requestId = $request->header('X-Request-ID') ?: (string) Str::uuid();

        // Populate baseline request metadata
        $this->contextStore->addContexts([
            'request_id' => $requestId,
            'method' => $request->method(),
            'path' => $request->path(),
            'full_url' => $request->fullUrl(),
            'ip' => $request->ip(),
            'user_agent' => $request->userAgent(),
            'timestamp' => now()->toISOString(),
        ]);

        // Add authenticated user information if available
        if ($request->user()) {
            $this->contextStore->addContext('user_id', $request->user()->getKey());
        }

        // Add request headers for correlation (optional, configurable in future)
        $correlationHeaders = [
            'x-request-id',
            'x-correlation-id',
            'x-trace-id',
        ];

        foreach ($correlationHeaders as $header) {
            if ($request->hasHeader($header)) {
                $this->contextStore->addContext(
                    str_replace('-', '_', $header),
                    $request->header($header)
                );
            }
        }

        return $next($request);
    }
}
