<?php

namespace Michael4d45\ContextLogging\Middleware;

use Closure;
use Illuminate\Http\Request;
use Michael4d45\ContextLogging\ContextStore;
use Michael4d45\ContextLogging\LoggingHelper;
use Michael4d45\ContextLogging\Profiling\SpxLifecycle;
use Illuminate\Support\Str;

/**
 * Request Context Middleware (Ingress).
 *
 * Seeds baseline request metadata and establishes correlation identifiers.
 * When request logging is enabled, adds an "Incoming Request" event with
 * masked headers and cookies (unless the route is ignored). Body and query
 * are included only when non-empty. Method/url/ip remain on outer context.
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
        // Merge buffered web-only pre-lifecycle events (e.g. route files, channels) into this request.
        $this->contextStore->initialize(true);
        SpxLifecycle::startIfEnabled();

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

        // Optional ingress event (method/url/ip already on outer context). Always emit when
        // enabled so timelines stay symmetric with Outgoing Response; omit empty payload keys.
        if (
            config('context-logging.log.request', false)
            && !LoggingHelper::shouldIgnoreRoute($request)
        ) {
            $payload = [
                'headers' => LoggingHelper::maskHeaders($request->headers->all()),
                'cookies' => LoggingHelper::maskCookies($request->cookies->all()),
                'timestamp' => now()->toISOString(),
            ];

            $body = LoggingHelper::maskSensitiveData($request->all());
            $query = LoggingHelper::maskSensitiveData($request->query());

            if ($body !== []) {
                $payload['body'] = $body;
            }
            if ($query !== []) {
                $payload['query_params'] = $query;
            }

            $this->contextStore->addEvent('info', 'Incoming Request', $payload);
        }

        return $next($request);
    }
}
