<?php

namespace Michael4d45\ContextLogging;

use Illuminate\Support\ServiceProvider;

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


        // Extend Laravel's logger to use contextual logging
        $this->app->extend('log', function ($originalLogger, $app) {
            return new ContextualLogger(
                $app->make(ContextStore::class),
                $originalLogger
            );
        });
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

        // Note: Middleware registration is intentionally NOT automatic.
        // Users must manually register RequestContextMiddleware and EmitContextMiddleware
        // in their bootstrap/app.php file to have explicit control over middleware ordering.
    }
}