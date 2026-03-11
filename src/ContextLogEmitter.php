<?php

namespace Michael4d45\ContextLogging;

use Illuminate\Log\LogManager;

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

    public static function emit(ContextStore $contextStore, ?int $statusCode, string $message): void
    {
        $contextStore->finalize($statusCode);

        if (!$contextStore->hasEvents()) {
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

        $originalLogManager = new LogManager(app());
        $originalLogManager->log($highestLevel, $message, $payload);
    }
}
