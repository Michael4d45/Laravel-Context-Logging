<?php

namespace Michael\ContextLogging;

use Illuminate\Support\ServiceProvider;
use Illuminate\Contracts\Http\Kernel;
use Michael\ContextLogging\Middleware\RequestContextMiddleware;
use Michael\ContextLogging\Middleware\EmitContextMiddleware;

/**
 * Context Logging Service Provider.
 *
 * Registers bindings, middleware, and integrations for the contextual logging system.
 */
class ContextLoggingServiceProvider extends ServiceProvider
{
    /**
     * Register services.
     */
    public function register(): void
    {
        // Register the ContextStore as a request-scoped singleton
        $this->app->singleton(ContextStore::class, function ($app) {
            return new ContextStore();
        });

        // Register the ContextualLogger
        $this->app->singleton('contextual-logger', function ($app) {
            return new ContextualLogger($app->make(ContextStore::class));
        });

        // Register middleware aliases
        $this->app['router']->aliasMiddleware('context.request', RequestContextMiddleware::class);
        $this->app['router']->aliasMiddleware('context.emit', EmitContextMiddleware::class);
    }

    /**
     * Bootstrap services.
     */
    public function boot(): void
    {
        // Publish configuration file (for future optional config)
        $this->publishes([
            __DIR__.'/../config/context-logging.php' => config_path('context-logging.php'),
        ], 'config');

        // Register terminating middleware globally
        // This ensures every HTTP request gets contextual logging
        $kernel = $this->app->make(Kernel::class);

        // Add request context middleware early in the stack
        $kernel->prependMiddleware(RequestContextMiddleware::class);

        // Add emit middleware as terminating middleware
        $kernel->pushMiddleware(EmitContextMiddleware::class);
    }
}
