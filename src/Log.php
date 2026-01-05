<?php

namespace Michael\ContextLogging;

use Illuminate\Support\Facades\Facade;

/**
 * Contextual Log Facade.
 *
 * Drop-in replacement for Laravel's Log facade that resolves to the contextual logger.
 * Maintains identical public API while changing semantics from immediate emission
 * to context accumulation.
 */
class Log extends Facade
{
    /**
     * Get the registered name of the component.
     */
    protected static function getFacadeAccessor(): string
    {
        return 'contextual-logger';
    }
}
