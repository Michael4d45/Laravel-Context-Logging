<?php

namespace Michael4d45\ContextLogging\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Michael4d45\ContextLogging\ContextStore;
use Michael4d45\ContextLogging\ContextLogEmitter;

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
        ContextLogEmitter::emit($this->contextStore, $response->getStatusCode(), 'Request completed');
    }
}
