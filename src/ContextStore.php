<?php

namespace Michael4d45\ContextLogging;

use Illuminate\Support\Collection;

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
     * Initialize the context store for a new request.
     */
    public function initialize(): void
    {
        $this->context = [];
        $this->events = [];
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
    public function finalize(int $statusCode = null): void
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
        return [
            'context' => $this->context,
            'events' => $this->events,
        ];
    }

    /**
     * Clear all stored data.
     */
    public function clear(): void
    {
        $this->context = [];
        $this->events = [];
        $this->startTime = null;
    }
}
