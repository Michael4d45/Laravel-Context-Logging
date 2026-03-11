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
        $this->bootMailLogging();
        $this->bootReverbLogging();
        $this->bootScheduleLogging();
        $this->bootNotificationsLogging();
        $this->bootBroadcastingLogging();

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

    protected function bootMailLogging(): void
    {
        if (!config('context-logging.log.mail', false)) {
            return;
        }

        $messageSending = \Illuminate\Mail\Events\MessageSending::class;
        $messageSent = \Illuminate\Mail\Events\MessageSent::class;
        if (!class_exists($messageSending) || !class_exists($messageSent)) {
            return;
        }

        $store = fn () => $this->app->make(ContextStore::class);
        $trace = fn () => TraceHelper::getCollapsedTrace();

        Event::listen($messageSending, function ($event) use ($store, $trace): void {
            $message = $event->message ?? $event->data['message'] ?? null;
            $store()->addEvent('debug', 'mail', [
                'event' => 'MessageSending',
                'to' => $message ? $this->mailRecipients($message) : null,
                'subject' => $message?->getSubject(),
                'trace' => $trace(),
            ]);
        });

        Event::listen($messageSent, function ($event) use ($store, $trace): void {
            $message = $event->message ?? $event->data['message'] ?? null;
            $store()->addEvent('debug', 'mail', [
                'event' => 'MessageSent',
                'to' => $message ? $this->mailRecipients($message) : null,
                'subject' => $message?->getSubject(),
                'trace' => $trace(),
            ]);
        });
    }

    /**
     * @param \Symfony\Component\Mime\Email $message
     * @return array<string>|null
     */
    private function mailRecipients($message): ?array
    {
        if (!method_exists($message, 'getTo')) {
            return null;
        }
        $to = $message->getTo();
        $addresses = [];
        foreach ($to as $addr) {
            $addresses[] = $addr->getAddress();
        }
        return $addresses ?: null;
    }

    protected function bootReverbLogging(): void
    {
        if (!config('context-logging.log.reverb', false)) {
            return;
        }

        $clientConnected = 'Laravel\Reverb\Events\ClientConnected';
        $clientDisconnected = 'Laravel\Reverb\Events\ClientDisconnected';
        $pusherSubscribe = 'Laravel\Reverb\Events\PusherSubscribe';
        $pusherUnsubscribe = 'Laravel\Reverb\Events\PusherUnsubscribe';

        if (!class_exists($clientConnected)) {
            return;
        }

        $store = fn () => $this->app->make(ContextStore::class);
        $trace = fn () => TraceHelper::getCollapsedTrace();

        $connectionId = function ($event): ?string {
            if (isset($event->connectionId)) {
                return $event->connectionId;
            }
            if (isset($event->connection) && is_object($event->connection) && method_exists($event->connection, 'id')) {
                return $event->connection->id();
            }
            return null;
        };

        Event::listen($clientConnected, function ($event) use ($store, $trace, $connectionId): void {
            $store()->addEvent('debug', 'reverb', [
                'event' => 'ClientConnected',
                'connection_id' => $connectionId($event),
                'trace' => $trace(),
            ]);
        });

        if (class_exists($clientDisconnected)) {
            Event::listen($clientDisconnected, function ($event) use ($store, $trace, $connectionId): void {
                $store()->addEvent('debug', 'reverb', [
                    'event' => 'ClientDisconnected',
                    'connection_id' => $connectionId($event),
                    'trace' => $trace(),
                ]);
            });
        }

        if (class_exists($pusherSubscribe)) {
            Event::listen($pusherSubscribe, function ($event) use ($store, $trace, $connectionId): void {
                $store()->addEvent('debug', 'reverb', [
                    'event' => 'PusherSubscribe',
                    'channel' => $event->channel ?? null,
                    'connection_id' => $connectionId($event),
                    'trace' => $trace(),
                ]);
            });
        }

        if (class_exists($pusherUnsubscribe)) {
            Event::listen($pusherUnsubscribe, function ($event) use ($store, $trace, $connectionId): void {
                $store()->addEvent('debug', 'reverb', [
                    'event' => 'PusherUnsubscribe',
                    'channel' => $event->channel ?? null,
                    'connection_id' => $connectionId($event),
                    'trace' => $trace(),
                ]);
            });
        }
    }

    protected function bootScheduleLogging(): void
    {
        if (!config('context-logging.log.schedule', false)) {
            return;
        }

        $taskStarting = \Illuminate\Console\Events\ScheduledTaskStarting::class;
        $taskFinished = \Illuminate\Console\Events\ScheduledTaskFinished::class;
        $taskFailed = \Illuminate\Console\Events\ScheduledTaskFailed::class;
        $taskSkipped = \Illuminate\Console\Events\ScheduledTaskSkipped::class;

        if (!class_exists($taskStarting)) {
            return;
        }

        $store = fn () => $this->app->make(ContextStore::class);
        $trace = fn () => TraceHelper::getCollapsedTrace();

        Event::listen($taskStarting, function ($event) use ($store, $trace): void {
            $store()->addEvent('debug', 'schedule', [
                'event' => 'ScheduledTaskStarting',
                'task' => $event->task->getSummaryForDisplay(),
                'trace' => $trace(),
            ]);
        });

        if (class_exists($taskFinished)) {
            Event::listen($taskFinished, function ($event) use ($store, $trace): void {
                $store()->addEvent('debug', 'schedule', [
                    'event' => 'ScheduledTaskFinished',
                    'task' => $event->task->getSummaryForDisplay(),
                    'runtime' => $event->runtime ?? null,
                    'trace' => $trace(),
                ]);
            });
        }

        if (class_exists($taskFailed)) {
            Event::listen($taskFailed, function ($event) use ($store): void {
                $store()->addEvent('error', 'schedule', [
                    'event' => 'ScheduledTaskFailed',
                    'task' => $event->task->getSummaryForDisplay(),
                    'exception' => $event->exception->getMessage(),
                    'trace' => TraceHelper::getCollapsedTrace(),
                ]);
            });
        }

        if (class_exists($taskSkipped)) {
            Event::listen($taskSkipped, function ($event) use ($store, $trace): void {
                $store()->addEvent('debug', 'schedule', [
                    'event' => 'ScheduledTaskSkipped',
                    'task' => $event->task->getSummaryForDisplay(),
                    'trace' => $trace(),
                ]);
            });
        }
    }

    protected function bootNotificationsLogging(): void
    {
        if (!config('context-logging.log.notifications', false)) {
            return;
        }

        $notificationSending = \Illuminate\Notifications\Events\NotificationSending::class;
        $notificationSent = \Illuminate\Notifications\Events\NotificationSent::class;
        $notificationFailed = \Illuminate\Notifications\Events\NotificationFailed::class;

        if (!class_exists($notificationSending) || !class_exists($notificationSent)) {
            return;
        }

        $store = fn () => $this->app->make(ContextStore::class);
        $trace = fn () => TraceHelper::getCollapsedTrace();
        $describeNotifiable = function ($notifiable): ?string {
            if ($notifiable === null) {
                return null;
            }
            if (is_object($notifiable) && method_exists($notifiable, 'getKey')) {
                return get_class($notifiable) . '#' . $notifiable->getKey();
            }
            return is_object($notifiable) ? get_class($notifiable) : (string) $notifiable;
        };

        Event::listen($notificationSending, function ($event) use ($store, $trace, $describeNotifiable): void {
            $store()->addEvent('debug', 'notifications', [
                'event' => 'NotificationSending',
                'channel' => $event->channel ?? null,
                'notification' => is_object($event->notification) ? get_class($event->notification) : null,
                'notifiable' => $describeNotifiable($event->notifiable ?? null),
                'trace' => $trace(),
            ]);
        });

        Event::listen($notificationSent, function ($event) use ($store, $trace, $describeNotifiable): void {
            $store()->addEvent('debug', 'notifications', [
                'event' => 'NotificationSent',
                'channel' => $event->channel ?? null,
                'notification' => is_object($event->notification) ? get_class($event->notification) : null,
                'notifiable' => $describeNotifiable($event->notifiable ?? null),
                'trace' => $trace(),
            ]);
        });

        if (class_exists($notificationFailed)) {
            Event::listen($notificationFailed, function ($event) use ($store, $describeNotifiable): void {
                $store()->addEvent('error', 'notifications', [
                    'event' => 'NotificationFailed',
                    'channel' => $event->channel ?? null,
                    'notification' => is_object($event->notification) ? get_class($event->notification) : null,
                    'notifiable' => $describeNotifiable($event->notifiable ?? null),
                    'exception' => $event->exception instanceof \Throwable ? $event->exception->getMessage() : null,
                    'trace' => TraceHelper::getCollapsedTrace(),
                ]);
            });
        }
    }

    protected function bootBroadcastingLogging(): void
    {
        if (!config('context-logging.log.broadcasting', false)) {
            return;
        }

        if (!class_exists(\Illuminate\Broadcasting\BroadcastEvent::class)) {
            return;
        }

        $store = fn () => $this->app->make(ContextStore::class);
        $trace = fn () => TraceHelper::getCollapsedTrace();

        $logBroadcastJob = function (string $eventName, $event) use ($store, $trace): void {
            $job = $event->job ?? null;
            if ($job === null || !$job instanceof \Illuminate\Broadcasting\BroadcastEvent) {
                return;
            }
            $eventClass = $job->displayName();
            $channels = null;
            $broadcastEvent = null;
            if (property_exists($job, 'event')) {
                try {
                    $ref = new \ReflectionProperty($job, 'event');
                    $broadcastEvent = $ref->getValue($job);
                } catch (\Throwable) {
                    // ignore
                }
            }
            if (is_object($broadcastEvent) && method_exists($broadcastEvent, 'broadcastOn')) {
                $channels = [];
                foreach ($broadcastEvent->broadcastOn() as $channel) {
                    $channels[] = $channel instanceof \Illuminate\Broadcasting\Channel
                        ? ($channel->name ?? (string) $channel)
                        : (string) $channel;
                }
            }
            $store()->addEvent('debug', 'broadcasting', array_filter([
                'event' => $eventName,
                'broadcast_event' => $eventClass,
                'channels' => $channels,
                'trace' => $trace(),
            ]));
        };

        Event::listen(\Illuminate\Queue\Events\JobProcessing::class, function ($event) use ($logBroadcastJob): void {
            $logBroadcastJob('BroadcastProcessing', $event);
        });

        Event::listen(\Illuminate\Queue\Events\JobProcessed::class, function ($event) use ($logBroadcastJob): void {
            $logBroadcastJob('BroadcastProcessed', $event);
        });
    }

    /**
     * Bootstrap context logging for console (artisan commands).
     * Initializes context without request info and emits on command finish.
     * Commands in config context-logging.console.skip_commands are not wrapped
     * (e.g. queue:work so each job logs separately).
     */
    protected function bootConsoleContext(): void
    {
        $this->app->booted(function () {
            $contextStore = $this->app->make(ContextStore::class);
            $skipEmit = [false];

            $this->app['events']->listen(CommandStarting::class, function (CommandStarting $event) use ($contextStore, &$skipEmit) {
                $command = $event->command ?? 'unknown';
                if ($this->shouldSkipConsoleCommand($command)) {
                    $skipEmit[0] = true;
                    return;
                }
                $skipEmit[0] = false;
                $contextStore->initialize();
                $contextStore->addContexts([
                    'run_id' => (string) Str::uuid(),
                    'timestamp' => now()->toISOString(),
                    'command' => $command,
                    'args' => $event->input->getArguments(),
                ]);
            });

            $this->app->terminating(function () use ($contextStore, &$skipEmit) {
                if ($skipEmit[0]) {
                    return;
                }
                ContextLogEmitter::emit($contextStore, null, 'Console run completed');
            });
        });
    }

    /**
     * Whether the given artisan command should skip console context wrapping.
     */
    protected function shouldSkipConsoleCommand(string $command): bool
    {
        $patterns = config('context-logging.console.skip_commands', []);
        foreach ($patterns as $pattern) {
            $pattern = trim((string) $pattern);
            if ($pattern !== '' && Str::is($pattern, $command)) {
                return true;
            }
        }
        return false;
    }
}