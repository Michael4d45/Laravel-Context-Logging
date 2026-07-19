<?php

namespace Michael4d45\ContextLogging;

use Illuminate\Log\LogManager;
use Michael4d45\ContextLogging\Profiling\ProfilerCorrelator;

/**
 * Emits the context store payload to the log.
 * Used by both HTTP middleware and console lifecycle.
 */
class ContextLogEmitter
{
    private const SEVERITY_LEVELS = [
        'emergency' => 0,
        'alert' => 1,
        'critical' => 2,
        'error' => 3,
        'warning' => 4,
        'notice' => 5,
        'info' => 6,
        'debug' => 7,
    ];

    public static function emit(
        ContextStore $contextStore,
        ?int $statusCode,
        string $message,
        ?ProfilerCorrelator $correlator = null,
    ): void {
        if ($contextStore->isEmissionSuppressed() || $contextStore->hasBeenEmitted()) {
            return;
        }

        $contextStore->finalize($statusCode);
        self::attachProfilerCorrelation($contextStore, $correlator);

        if (!$contextStore->hasEvents()) {
            $contextStore->markEmitted();

            return;
        }

        $payload = $contextStore->getPayload();
        $highestLevel = 'debug';
        $highestPriority = 7;

        foreach ($payload['events'] as $event) {
            $priority = self::SEVERITY_LEVELS[$event['level']] ?? 7;
            if ($priority < $highestPriority) {
                $highestPriority = $priority;
                $highestLevel = $event['level'];
            }
        }

        $contextStore->markEmitted();

        $originalLogManager = new LogManager(app());
        $originalLogManager->log($highestLevel, $message, $payload);
    }

    /**
     * Attach native profiler join keys to the outer context when correlation is enabled.
     */
    private static function attachProfilerCorrelation(
        ContextStore $contextStore,
        ?ProfilerCorrelator $correlator = null,
    ): void {
        try {
            $refs = ($correlator ?? new ProfilerCorrelator())->detect();
        } catch (\Throwable) {
            return;
        }

        if ($refs === []) {
            return;
        }

        $contextStore->addContext('profile', $refs[0]->toArray());

        if (count($refs) > 1) {
            $contextStore->addContext(
                'profiles',
                array_map(static fn ($ref) => $ref->toArray(), $refs),
            );
        }
    }

    /**
     * Emit a single event immediately when no request/job/command lifecycle is active.
     *
     * Used so cache, SQL, etc. are logged as they happen instead of accumulating
     * until the next lifecycle (e.g. queue worker idle polling).
     *
     * @param  array{level: string, message: string, context: array, timestamp: float}  $event
     */
    public static function emitStandaloneEvent(array $event): void
    {
        $payload = [
            'context' => [],
            'events' => [$event],
        ];

        $originalLogManager = new LogManager(app());
        $originalLogManager->log($event['level'], $event['message'], $payload);
    }

    /**
     * Emit the current lifecycle during shutdown when normal termination did not run.
     *
     * @param array<string, mixed>|null $lastError
     */
    public static function emitInterruptedLifecycle(ContextStore $contextStore, ?array $lastError = null): void
    {
        if (
            !$contextStore->hasLifecycleStarted()
            || $contextStore->isEmissionSuppressed()
            || $contextStore->hasBeenEmitted()
        ) {
            return;
        }

        $isFatalError = self::isFatalError($lastError);

        if ($isFatalError) {
            $contextStore->addEvent('critical', 'PHP fatal error', [
                'type' => $lastError['type'] ?? null,
                'message' => $lastError['message'] ?? null,
                'file' => $lastError['file'] ?? null,
                'line' => $lastError['line'] ?? null,
            ]);
        } elseif (!$contextStore->hasEvents()) {
            $contextStore->addEvent('warning', self::defaultInterruptionEvent($contextStore), []);
        }

        self::emit(
            $contextStore,
            self::defaultInterruptedStatusCode($contextStore, $isFatalError),
            self::defaultInterruptedMessage($contextStore, $isFatalError)
        );
    }

    /**
     * @param array<string, mixed>|null $lastError
     */
    public static function isFatalError(?array $lastError): bool
    {
        if ($lastError === null) {
            return false;
        }

        return in_array((int) ($lastError['type'] ?? 0), [
            E_ERROR,
            E_PARSE,
            E_CORE_ERROR,
            E_COMPILE_ERROR,
            E_USER_ERROR,
            E_RECOVERABLE_ERROR,
        ], true);
    }

    private static function defaultInterruptedMessage(ContextStore $contextStore, bool $isFatalError): string
    {
        if ($contextStore->getContext('source') === 'tinker') {
            return $isFatalError ? 'Tinker execution failed' : 'Tinker execution interrupted';
        }

        if ($contextStore->getContext('method') !== null) {
            return $isFatalError ? 'Request failed' : 'Request interrupted';
        }

        return $isFatalError ? 'Console run failed' : 'Console run interrupted';
    }

    private static function defaultInterruptionEvent(ContextStore $contextStore): string
    {
        if ($contextStore->getContext('source') === 'tinker') {
            return 'Tinker execution interrupted';
        }

        if ($contextStore->getContext('method') !== null) {
            return 'Request interrupted';
        }

        return 'Console run interrupted';
    }

    private static function defaultInterruptedStatusCode(ContextStore $contextStore, bool $isFatalError): ?int
    {
        if ($isFatalError && $contextStore->getContext('method') !== null) {
            return 500;
        }

        return null;
    }
}
