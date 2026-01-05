<?php

namespace Michael4d45\ContextLogging\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Michael4d45\ContextLogging\ContextStore;
use Illuminate\Support\Facades\Log;

/**
 * Emit Context Middleware (Terminating).
 *
 * Finalizes request context, computes duration, and emits a single structured log entry.
 * Executes after the response is sent to avoid impacting response time.
 */
class EmitContextMiddleware
{
    public function __construct(
        protected ContextStore $contextStore
    ) {}

    /**
     * Handle an incoming request.
     */
    public function handle(Request $request, Closure $next)
    {
        return $next($request);
    }

    /**
     * Handle the terminating middleware.
     */
    public function terminate(Request $request, Response $response): void
    {
        // Finalize the context with response information
        $this->contextStore->finalize($response->getStatusCode());

        // Only emit if we have events to log
        if (!$this->contextStore->hasEvents()) {
            return;
        }

        // Get the structured payload
        $payload = $this->contextStore->getPayload();

        // Determine the highest severity level from events
        $severityLevels = [
            'emergency' => 0,
            'alert' => 1,
            'critical' => 2,
            'error' => 3,
            'warning' => 4,
            'notice' => 5,
            'info' => 6,
            'debug' => 7,
        ];

        $highestLevel = 'debug';
        $highestPriority = 7;

        foreach ($payload['events'] as $event) {
            $priority = $severityLevels[$event['level']] ?? 7;
            if ($priority < $highestPriority) {
                $highestPriority = $priority;
                $highestLevel = $event['level'];
            }
        }

        // Emit the structured log using Laravel's standard logger
        // This ensures full compatibility with existing logging configuration
        Log::log($highestLevel, 'Request completed', $payload);
    }
}
