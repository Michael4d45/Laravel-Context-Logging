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
            $livewireActions = $this->livewireActions($request);
            if ($livewireActions !== []) {
                $payload['livewire_actions'] = $livewireActions;
            }
            if ($query !== []) {
                $payload['query_params'] = $query;
            }

            $this->contextStore->addEvent('info', 'Incoming Request', $payload);
        }

        return $next($request);
    }

    /**
     * Preserve a shallow, source-navigable summary before Monolog truncates
     * Livewire's deeply nested component payload.
     *
     * @return list<array{
     *     component: string,
     *     component_id: string|null,
     *     method: string|null,
     *     action: string|null
     * }>
     */
    private function livewireActions(Request $request): array
    {
        if (
            ! $request->headers->has('X-Livewire')
            && ! preg_match('#(?:^|/)livewire(?:-[^/]+)?/update$#', $request->path())
        ) {
            return [];
        }

        $components = $request->input('components', []);
        if (! is_array($components)) {
            return [];
        }

        $actions = [];
        foreach (array_slice($components, 0, 20) as $componentPayload) {
            if (! is_array($componentPayload)) {
                continue;
            }

            $snapshot = $componentPayload['snapshot'] ?? null;
            $snapshotData = is_string($snapshot) ? json_decode($snapshot, true) : $snapshot;
            $memo = is_array($snapshotData) && is_array($snapshotData['memo'] ?? null)
                ? $snapshotData['memo']
                : [];
            $component = is_string($memo['name'] ?? null) ? $memo['name'] : null;
            if ($component === null || $component === '') {
                continue;
            }

            $componentId = is_string($memo['id'] ?? null) ? $memo['id'] : null;
            $calls = is_array($componentPayload['calls'] ?? null) ? $componentPayload['calls'] : [];

            if ($calls === []) {
                $actions[] = [
                    'component' => $component,
                    'component_id' => $componentId,
                    'method' => null,
                    'action' => null,
                ];
                continue;
            }

            foreach (array_slice($calls, 0, 20) as $call) {
                if (! is_array($call)) {
                    continue;
                }

                $method = is_string($call['method'] ?? null) ? $call['method'] : null;
                $params = is_array($call['params'] ?? null) ? $call['params'] : [];
                $action = isset($params[0]) && is_scalar($params[0])
                    ? (string) $params[0]
                    : null;

                $actions[] = [
                    'component' => $component,
                    'component_id' => $componentId,
                    'method' => $method,
                    'action' => $action,
                ];
            }
        }

        return $actions;
    }
}
