<?php

namespace Michael4d45\ContextLogging\Middleware;

use Closure;
use Illuminate\Http\Request;
use Michael4d45\ContextLogging\ContextStore;
use Michael4d45\ContextLogging\LoggingHelper;
use Illuminate\Support\Str;

/**
 * Request Context Middleware (Ingress).
 *
 * Seeds baseline request metadata and establishes correlation identifiers.
 * When request logging is enabled, adds an "Incoming Request" event with
 * masked body, query, headers, and cookies (unless the route is ignored).
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
        // Promote any bootstrap-time events into the request lifecycle.
        $this->contextStore->initialize(true);

        if (LoggingHelper::shouldIgnoreRoute($request)) {
            $this->contextStore->suppressEmission();
        }

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

        $correlationHeaders = ['x-request-id', 'x-correlation-id', 'x-trace-id'];
        foreach ($correlationHeaders as $header) {
            if ($request->hasHeader($header)) {
                $this->contextStore->addContext(str_replace('-', '_', $header), $request->header($header));
            }
        }

        // Optional: log incoming request body/query as event (method, url, ip, user_agent are already in context) when enabled and route not ignored
        if (
            config('context-logging.log.request', false)
            && !LoggingHelper::shouldIgnoreRoute($request)
        ) {
            $body = $request->all();
            $query = $request->query();

            if ($body !== [] || $query !== []) {
                $this->contextStore->addEvent('info', 'Incoming Request', [
                    'headers' => LoggingHelper::maskHeaders($request->headers->all()),
                    'body' => LoggingHelper::maskSensitiveData($body),
                    'query_params' => LoggingHelper::maskSensitiveData($query),
                    'cookies' => LoggingHelper::maskCookies($request->cookies->all()),
                    'timestamp' => now()->toISOString(),
                ]);
            }
        }

        return $next($request);
    }
}
