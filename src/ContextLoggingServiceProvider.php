<?php

namespace Michael4d45\ContextLogging;

use Illuminate\Cache\Events\CacheHit;
use Illuminate\Cache\Events\CacheMissed;
use Illuminate\Cache\Events\KeyForgotten;
use Illuminate\Cache\Events\KeyWritten;
use Illuminate\Console\Events\CommandStarting;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Queue\Events\JobFailed;
use Illuminate\Queue\Events\JobProcessed;
use Illuminate\Queue\Events\JobProcessing;
use Illuminate\Queue\Events\JobQueued;
use Illuminate\Queue\Events\QueueBusy;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Str;
use Michael4d45\ContextLogging\Support\TraceHelper;

/**
 * Context Logging Service Provider.
 *
 * Registers bindings, middleware, and integrations for the contextual logging system.
 * Optionally registers DB, cache, and queue event listeners to add service events
 * to the same context log as HTTP request/response.
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
            $this->commands([
                Console\MonitorLogsCommand::class,
            ]);
        }

        $this->bootDatabaseLogging();
        $this->bootCacheLogging();
        $this->bootQueueLogging();

        // Note: Middleware registration is intentionally NOT automatic.
        // Users must manually register RequestContextMiddleware and EmitContextMiddleware
        // in their bootstrap/app.php file to have explicit control over middleware ordering.
    }

    protected function bootDatabaseLogging(): void
    {
        if (!config('context-logging.log.db', false)) {
            return;
        }

        DB::listen(function (QueryExecuted $query): void {
            $this->app->make(ContextStore::class)->addEvent('debug', 'sql', [
                'SQL' => $query->toRawSql() . ';',
                'execution_time' => $query->time . 'ms',
                'trace' => TraceHelper::getCollapsedTrace(),
            ]);
        });
    }

    protected function bootCacheLogging(): void
    {
        if (!config('context-logging.log.cache', false)) {
            return;
        }

        $addCacheEvent = function (string $eventName, array $context): void {
            $this->app->make(ContextStore::class)->addEvent('debug', 'cache', array_merge(
                ['event' => $eventName, 'trace' => TraceHelper::getCollapsedTrace()],
                $context
            ));
        };

        Event::listen(CacheHit::class, function (CacheHit $event) use ($addCacheEvent): void {
            $addCacheEvent('CacheHit', ['key' => $event->key]);
        });

        Event::listen(CacheMissed::class, function (CacheMissed $event) use ($addCacheEvent): void {
            $addCacheEvent('CacheMissed', ['key' => $event->key]);
        });

        Event::listen(KeyWritten::class, function (KeyWritten $event) use ($addCacheEvent): void {
            $addCacheEvent('KeyWritten', ['key' => $event->key, 'expiration' => $event->seconds]);
        });

        Event::listen(KeyForgotten::class, function (KeyForgotten $event) use ($addCacheEvent): void {
            $addCacheEvent('KeyForgotten', ['key' => $event->key]);
        });
    }

    protected function bootQueueLogging(): void
    {
        if (!config('context-logging.log.queue', false)) {
            return;
        }

        $store = fn () => $this->app->make(ContextStore::class);
        $trace = fn () => TraceHelper::getCollapsedTrace();

        Event::listen(JobQueued::class, function (JobQueued $event) use ($store, $trace): void {
            $job = $event->job;
            $jobName = is_object($job) ? get_class($job) : (is_callable($job) ? 'Closure' : $job);

            $store()->addEvent('debug', 'queue', [
                'event' => 'JobQueued',
                'job' => $jobName,
                'connection' => $event->connectionName,
                'queue' => $event->queue,
                'trace' => $trace(),
            ]);
        });

        Event::listen(JobProcessing::class, function (JobProcessing $event) use ($store, $trace): void {
            $contextStore = $store();
            $contextStore->initialize();
            $jobId = method_exists($event->job, 'getJobId') ? $event->job->getJobId() : null;
            $contextStore->addContexts([
                'job_id' => $jobId,
                'job' => $event->job->getName(),
                'queue' => $event->job->getQueue(),
                'connection' => $event->connectionName,
                'timestamp' => now()->toISOString(),
            ]);
            $contextStore->addEvent('debug', 'queue', [
                'event' => 'JobProcessing',
                'job' => $event->job->getName(),
                'queue' => $event->job->getQueue(),
                'attempts' => $event->job->attempts(),
                'connection' => $event->connectionName,
                'trace' => $trace(),
            ]);
        });

        Event::listen(JobProcessed::class, function (JobProcessed $event) use ($store, $trace): void {
            $contextStore = $store();
            $contextStore->addEvent('debug', 'queue', [
                'event' => 'JobProcessed',
                'job' => $event->job->getName(),
                'queue' => $event->job->getQueue(),
                'attempts' => $event->job->attempts(),
                'connection' => $event->connectionName,
                'trace' => $trace(),
            ]);
            ContextLogEmitter::emit($contextStore, null, 'Job completed');
            $contextStore->initialize();
        });

        Event::listen(JobFailed::class, function (JobFailed $event) use ($store, $trace): void {
            $contextStore = $store();
            $contextStore->addEvent('error', 'queue', [
                'event' => 'JobFailed',
                'job' => $event->job->getName(),
                'queue' => $event->job->getQueue(),
                'attempts' => $event->job->attempts(),
                'connection' => $event->connectionName,
                'exception' => $event->exception->getMessage(),
                'trace' => $trace(),
            ]);
            ContextLogEmitter::emit($contextStore, null, 'Job failed');
            $contextStore->initialize();
        });

        Event::listen(QueueBusy::class, function (QueueBusy $event) use ($store): void {
            $store()->addEvent('warning', 'queue', [
                'event' => 'QueueBusy',
                'connection' => $event->connection,
                'queue' => $event->queue,
                'size' => $event->size,
            ]);
        });
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