<?php

namespace Michael4d45\ContextLogging;

/**
 * Public hook registration API for outbound HTTP context enrichment.
 */
class HttpContextHooks
{
    /**
     * @var array<int, callable>
     */
    protected static array $beforeRequestHooks = [];

    /**
     * @var array<int, callable>
     */
    protected static array $afterResponseHooks = [];

    /**
     * Register a hook that runs before an outbound request is stored.
     */
    public static function beforeRequest(callable $hook): void
    {
        self::$beforeRequestHooks[] = $hook;
    }

    /**
     * Register a hook that runs after an outbound response is stored.
     */
    public static function afterResponse(callable $hook): void
    {
        self::$afterResponseHooks[] = $hook;
    }

    /**
     * @return array<int, callable>
     */
    public static function getBeforeRequestHooks(): array
    {
        return self::$beforeRequestHooks;
    }

    /**
     * @return array<int, callable>
     */
    public static function getAfterResponseHooks(): array
    {
        return self::$afterResponseHooks;
    }

    /**
     * Clear all registered hooks (primarily for testing).
     */
    public static function clear(): void
    {
        self::$beforeRequestHooks = [];
        self::$afterResponseHooks = [];
    }
}