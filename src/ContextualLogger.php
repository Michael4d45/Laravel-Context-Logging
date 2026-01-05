<?php

namespace Michael4d45\ContextLogging;

use Illuminate\Log\LogManager;

/**
 * Contextual Logger Implementation.
 *
 * Extends Laravel's LogManager but returns a contextual logger for logging.
 * All log calls become context annotations in the ContextStore.
 */
class ContextualLogger extends LogManager
{
    public function __construct(
        protected ContextStore $contextStore
    ) {
        // Call parent constructor with the application
        parent::__construct(app());
    }

    /**
     * Override logging methods to accumulate in context store
     */
    public function emergency($message, array $context = []): void
    {
        $this->log('emergency', $message, $context);
    }

    public function alert($message, array $context = []): void
    {
        $this->log('alert', $message, $context);
    }

    public function critical($message, array $context = []): void
    {
        $this->log('critical', $message, $context);
    }

    public function error($message, array $context = []): void
    {
        $this->log('error', $message, $context);
    }

    public function warning($message, array $context = []): void
    {
        $this->log('warning', $message, $context);
    }

    public function notice($message, array $context = []): void
    {
        $this->log('notice', $message, $context);
    }

    public function info($message, array $context = []): void
    {
        $this->log('info', $message, $context);
    }

    public function debug($message, array $context = []): void
    {
        $this->log('debug', $message, $context);
    }

    public function log($level, $message, array $context = []): void
    {
        // Only accumulate in context store for wide event (no pass-through)
        $this->contextStore->addEvent(
            (string) $level,
            (string) $message,
            $context
        );
    }
}