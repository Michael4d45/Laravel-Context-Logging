<?php

namespace Michael4d45\ContextLogging\Guzzle;

use GuzzleHttp\Client;
use GuzzleHttp\HandlerStack;
use Michael4d45\ContextLogging\HttpClientInstrumentation;

/**
 * Runtime gate for the transparent GuzzleHttp\Client constructor patch.
 */
class ClientPatch
{
    protected static ?bool $forced = null;

    protected static bool $missingPatchWarned = false;

    /**
     * Force patch on/off (tests). Null restores config/env detection.
     */
    public static function force(?bool $enabled): void
    {
        self::$forced = $enabled;
    }

    public static function isActive(): bool
    {
        if (self::$forced !== null) {
            return self::$forced;
        }

        if (function_exists('config') && function_exists('app')) {
            try {
                if (app()->bound('config')) {
                    return (bool) config('context-logging.http.enabled', false)
                        && (bool) config('context-logging.http.guzzle_patch', false);
                }
            } catch (\Throwable) {
                // Fall through to env.
            }
        }

        return self::envBool('CONTEXT_LOG_HTTP_ENABLED')
            && self::envBool('CONTEXT_LOG_HTTP_GUZZLE_PATCH');
    }

    /**
     * Whether GuzzleHttp\Client is the instrumented subclass.
     */
    public static function isClientPatched(): bool
    {
        if (! class_exists(\GuzzleHttp\Client::class, true)) {
            return false;
        }

        if (! class_exists(\GuzzleHttp\UnpatchedClient::class, false)
            && ! class_exists(\GuzzleHttp\UnpatchedClient::class, true)) {
            return false;
        }

        return is_subclass_of(\GuzzleHttp\Client::class, \GuzzleHttp\UnpatchedClient::class);
    }

    /**
     * @param  array<string, mixed>  $config
     * @return array<string, mixed>
     */
    public static function apply(array $config): array
    {
        if (! self::isActive()) {
            return $config;
        }

        if (! function_exists('app')) {
            return $config;
        }

        try {
            if (! app()->bound(HttpClientInstrumentation::class)) {
                return $config;
            }

            return app(HttpClientInstrumentation::class)->applyToClientConfig($config);
        } catch (\Throwable) {
            return $config;
        }
    }

    /**
     * Ensure middleware is on the client handler after construction (covers cases where
     * Guzzle created the HandlerStack itself, e.g. transport_sharing).
     */
    public static function afterConstruct(Client $client): void
    {
        if (! self::isActive()) {
            return;
        }

        if (! function_exists('app') || ! app()->bound(HttpClientInstrumentation::class)) {
            return;
        }

        try {
            $handler = $client->getConfig('handler');
            if ($handler instanceof HandlerStack) {
                app(HttpClientInstrumentation::class)->pushOnto($handler);
            }
        } catch (\Throwable) {
            // Never break outbound HTTP because of instrumentation.
        }
    }

    public static function warnIfPatchMissing(): void
    {
        if (self::$missingPatchWarned || ! self::isActive() || self::isClientPatched()) {
            return;
        }

        self::$missingPatchWarned = true;

        $message = 'context-logging: http.guzzle_patch is enabled but GuzzleHttp\\Client is not patched. '
            .'Falling back to Http::globalMiddleware only. Run `composer dump-autoload` '
            .'(with allow-plugins for michael4d45/context-logging) or '
            .'`php vendor/bin/install-guzzle-patch.php`.';

        if (function_exists('logger')) {
            try {
                logger()->warning($message);

                return;
            } catch (\Throwable) {
                // Fall through.
            }
        }

        error_log($message);
    }

    protected static function envBool(string $key): bool
    {
        $value = $_ENV[$key] ?? $_SERVER[$key] ?? getenv($key);

        if ($value === false || $value === null || $value === '') {
            return false;
        }

        return filter_var($value, FILTER_VALIDATE_BOOLEAN);
    }
}
