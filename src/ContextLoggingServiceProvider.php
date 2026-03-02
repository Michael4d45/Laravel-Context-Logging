<?php

namespace Michael4d45\ContextLogging;

use Illuminate\Support\ServiceProvider;
use Illuminate\Http\Client\Factory as HttpFactory;

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
        $this->mergeConfigFrom(
            __DIR__.'/../config/context-logging.php',
            'context-logging'
        );

        $this->app->singleton(HttpContextHookRunner::class, function ($app) {
            return new HttpContextHookRunner();
        });

        $this->app->singleton(HttpClientInstrumentation::class, function ($app) {
            return new HttpClientInstrumentation(
                $app->make(HttpFactory::class),
                $app->make(ContextStore::class),
            );
        });

        // Register the ContextStore as a request-scoped singleton
        $this->app->singleton(ContextStore::class, function ($app) {
            return new ContextStore(
                $app->make(HttpContextHookRunner::class),
                (bool) $app['config']->get('context-logging.http.enabled', true),
            );
        });


        // Extend Laravel's logger to use contextual logging
        $this->app->extend('log', function ($originalLogger, $app) {
            return new ContextualLogger(
                $app->make(ContextStore::class)
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

        $this->app->booted(function () {
            if (!(bool) config('context-logging.http.enabled', true)) {
                return;
            }

            $this->app->make(HttpClientInstrumentation::class)->register();
        });

        // Note: Middleware registration is intentionally NOT automatic.
        // Users must manually register RequestContextMiddleware and EmitContextMiddleware
        // in their bootstrap/app.php file to have explicit control over middleware ordering.
    }
}