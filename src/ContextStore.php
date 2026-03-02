<?php

namespace Michael4d45\ContextLogging;

use Illuminate\Support\Str;

/**
 * Context Store for accumulating request-wide metadata and log events.
 *
 * This is a request-scoped singleton that accumulates contextual information
 * throughout a request's lifecycle and provides a final structured payload.
 */
class ContextStore
{
    /**
     * Request-wide metadata.
     */
    protected array $context = [];

    /**
     * Accumulated log events.
     */
    protected array $events = [];

    /**
     * Start timestamp for duration calculation.
     */
    protected ?float $startTime = null;

    /**
     * Accumulated outbound HTTP request/response sub-context.
     *
     * @var array<string, array<string, mixed>>
     */
    protected array $httpCalls = [];

    /**
     * Controls whether outbound HTTP sub-context tracking is active.
     */
    protected bool $httpEnabled = true;

    public function __construct(
        protected ?HttpContextHookRunner $httpHookRunner = null,
        ?bool $httpEnabled = null,
    ) {
        if ($httpEnabled !== null) {
            $this->httpEnabled = $httpEnabled;
        }
    }

    /**
     * Initialize the context store for a new request.
     */
    public function initialize(): void
    {
        $this->context = [];
        $this->events = [];
        $this->httpCalls = [];
        $this->startTime = microtime(true);
    }

    /**
     * Add request metadata to the context.
     */
    public function addContext(string $key, mixed $value): void
    {
        $this->context[$key] = $value;
    }

    /**
     * Add multiple context items at once.
     */
    public function addContexts(array $contexts): void
    {
        $this->context = array_merge($this->context, $contexts);
    }

    /**
     * Get a context value.
     */
    public function getContext(string $key): mixed
    {
        return $this->context[$key] ?? null;
    }

    /**
     * Get all context data.
     */
    public function getAllContext(): array
    {
        return $this->context;
    }

    /**
     * Add a log event annotation.
     */
    public function addEvent(string $level, string $message, array $context = []): void
    {
        $this->events[] = [
            'level' => $level,
            'message' => $message,
            'context' => $context,
            'timestamp' => microtime(true),
        ];
    }

    /**
     * Get all accumulated events.
     */
    public function getEvents(): array
    {
        return $this->events;
    }

    /**
     * Check if the context store has any events.
     */
    public function hasEvents(): bool
    {
        return !empty($this->events);
    }

    /**
     * Finalize the context with request completion data.
     */
    public function finalize(?int $statusCode = null): void
    {
        if ($this->startTime !== null) {
            $this->context['duration_ms'] = round((microtime(true) - $this->startTime) * 1000, 2);
        }

        if ($statusCode !== null) {
            $this->context['status'] = $statusCode;
        }
    }

    /**
     * Get the final structured payload for logging.
     */
    public function getPayload(): array
    {
        $payload = [
            'context' => $this->context,
            'events' => $this->events,
        ];

        if (!empty($this->httpCalls)) {
            $payload['http_calls'] = array_values($this->httpCalls);
        }

        return $payload;
    }

    /**
     * Clear all stored data.
     */
    public function clear(): void
    {
        $this->context = [];
        $this->events = [];
        $this->httpCalls = [];
        $this->startTime = null;
    }

    /**
     * Begin tracking an outbound HTTP call and return the generated call ID.
     */
    public function beginHttpCall(array $request): string
    {
        if (!$this->httpEnabled) {
            return '';
        }

        $id = (string) Str::uuid();

        $normalizedRequest = array_merge($request, [
            'timestamp' => microtime(true),
        ]);

        if ($this->httpHookRunner !== null) {
            $normalizedRequest = $this->httpHookRunner->runBeforeRequest($normalizedRequest);
        }

        $this->httpCalls[$id] = [
            'id' => $id,
            'request' => $normalizedRequest,
            'context' => [],
        ];

        return $id;
    }

    /**
     * Add extra sub-context for a tracked outbound HTTP call.
     */
    public function addHttpContext(string $id, array $extra): void
    {
        if (!$this->httpEnabled || $id === '') {
            return;
        }

        if (!isset($this->httpCalls[$id])) {
            $this->httpCalls[$id] = [
                'id' => $id,
                'request' => [],
                'context' => [],
            ];
        }

        $this->httpCalls[$id]['context'] = array_merge(
            $this->httpCalls[$id]['context'],
            $extra
        );
    }

    /**
     * Complete tracking for an outbound HTTP call by attaching response data.
     */
    public function completeHttpCall(string $id, array $response): void
    {
        if (!$this->httpEnabled || $id === '') {
            return;
        }

        if (!isset($this->httpCalls[$id])) {
            $this->httpCalls[$id] = [
                'id' => $id,
                'request' => [],
                'context' => [],
            ];
        }

        $normalizedResponse = array_merge($response, [
            'timestamp' => microtime(true),
        ]);

        $requestTimestamp = $this->httpCalls[$id]['request']['timestamp'] ?? null;

        if ($requestTimestamp !== null && !array_key_exists('duration_ms', $normalizedResponse)) {
            $normalizedResponse['duration_ms'] = round((microtime(true) - (float) $requestTimestamp) * 1000, 2);
        }

        if ($this->httpHookRunner !== null) {
            $normalizedResponse = $this->httpHookRunner->runAfterResponse(
                $this->httpCalls[$id]['request'],
                $normalizedResponse,
                $this->httpCalls[$id]['context'],
            );
        }

        $this->httpCalls[$id]['response'] = $normalizedResponse;
    }

    /**
     * Get tracked outbound HTTP calls.
     *
     * @return array<int, array<string, mixed>>
     */
    public function getHttpCalls(): array
    {
        return array_values($this->httpCalls);
    }
}
