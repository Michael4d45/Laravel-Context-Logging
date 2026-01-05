<?php

namespace Michael4d45\ContextLogging;

use Illuminate\Log\Logger;
use Psr\Log\LoggerInterface;

/**
 * Contextual Logger Implementation.
 *
 * Extends Laravel's Logger but accumulates events instead of emitting them.
 * All log calls become context annotations in the ContextStore.
 */
class ContextualLogger extends Logger
{
    public function __construct(
        protected ContextStore $contextStore,
        protected $originalLogger = null
    ) {
        // Call parent constructor with the original logger to maintain compatibility
        // If no logger provided, use a dummy logger for testing
        $loggerToUse = $originalLogger ?: new \Psr\Log\NullLogger();
        parent::__construct($loggerToUse, app('events'));
    }

    /**
     * {@inheritdoc}
     */
    public function emergency($message, array $context = []): void
    {
        $this->log('emergency', $message, $context);
    }

    /**
     * {@inheritdoc}
     */
    public function alert($message, array $context = []): void
    {
        $this->log('alert', $message, $context);
    }

    /**
     * {@inheritdoc}
     */
    public function critical($message, array $context = []): void
    {
        $this->log('critical', $message, $context);
    }

    /**
     * {@inheritdoc}
     */
    public function error($message, array $context = []): void
    {
        $this->log('error', $message, $context);
    }

    /**
     * {@inheritdoc}
     */
    public function warning($message, array $context = []): void
    {
        $this->log('warning', $message, $context);
    }

    /**
     * {@inheritdoc}
     */
    public function notice($message, array $context = []): void
    {
        $this->log('notice', $message, $context);
    }

    /**
     * {@inheritdoc}
     */
    public function info($message, array $context = []): void
    {
        $this->log('info', $message, $context);
    }

    /**
     * {@inheritdoc}
     */
    public function debug($message, array $context = []): void
    {
        $this->log('debug', $message, $context);
    }

    /**
     * {@inheritdoc}
     */
    public function log($level, $message, array $context = []): void
    {
        // Forward to original logger for normal logging behavior
        if ($this->originalLogger) {
            $this->originalLogger->log($level, $message, $context);
        }

        // Also accumulate in context store for wide event
        $this->contextStore->addEvent(
            (string) $level,
            (string) $message,
            $context
        );
    }
}
