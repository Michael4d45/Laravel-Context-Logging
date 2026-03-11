<?php

namespace Michael4d45\ContextLogging;

use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Str;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Console\Events\CommandStarting;

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

        if ($this->app->runningInConsole()) {
            $this->bootConsoleContext();
        }

        // Note: Middleware registration is intentionally NOT automatic.
        // Users must manually register RequestContextMiddleware and EmitContextMiddleware
        // in their bootstrap/app.php file to have explicit control over middleware ordering.
    }

    /**
     * Bootstrap context logging for console (artisan commands).
     * Initializes context without request info and emits on command finish.
     */
    protected function bootConsoleContext(): void
    {
        $this->app->booted(function () {
            $contextStore = $this->app->make(ContextStore::class);

            $this->app['events']->listen(CommandStarting::class, function (CommandStarting $event) use ($contextStore) {
                $contextStore->initialize();
                $contextStore->addContexts([
                    'run_id' => (string) Str::uuid(),
                    'timestamp' => now()->toISOString(),
                    'command' => $event->command ?? 'unknown',
                ]);
            });

            $this->app->terminating(function () use ($contextStore) {
                ContextLogEmitter::emit($contextStore, null, 'Console run completed');
            });
        });
    }
}