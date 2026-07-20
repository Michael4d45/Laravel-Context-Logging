<?php

declare(strict_types=1);

namespace Michael4d45\ContextLogging\Profiling;

/**
 * Optional SPX lifecycle helpers so emit-time correlation can obtain a report key.
 *
 * SPX's programmatic start/stop API reads process env (not PHP cookies). When the
 * browser enables profiling via cookies, we mirror those into putenv() before
 * starting so SPX_REPORT=full yields a report key from spx_profiler_stop().
 *
 * For local apps, profiling.spx.auto_enable starts SPX on every request without
 * requiring the SPX control-panel cookie.
 *
 * Call stopAndCaptureKey() at emit time — before PHP shutdown — so the chip can
 * deep-link to /report.html&key=… instead of the SPX control panel root.
 *
 * HTTP requirements (php.ini / FPM — putenv alone is not enough):
 * - spx.http_profiling_enabled=1
 * - spx.http_profiling_auto_start=0
 * - env SPX_REPORT=full (and ideally SPX_AUTO_START=0)
 */
final class SpxLifecycle
{
    private static bool $started = false;

    private static ?string $lastReportKey = null;

    public static function startIfEnabled(): void
    {
        if (! extension_loaded('spx') || ! function_exists('spx_profiler_start')) {
            return;
        }

        if (! self::isEnabled()) {
            return;
        }

        // New lifecycle — drop any prior key from an earlier request/job in this worker.
        self::$lastReportKey = null;
        self::mirrorHttpParamsToEnv();

        // Full reports are required for stop() to return a UI key.
        putenv('SPX_REPORT=full');
        $_ENV['SPX_REPORT'] = 'full';

        try {
            spx_profiler_start();
            self::$started = true;
        } catch (\Throwable) {
            // Ignore — correlation will simply omit profile_id.
        }
    }

    /**
     * Stop the active SPX span and return the full-report key when available.
     */
    public static function stopAndCaptureKey(): ?string
    {
        if (self::$lastReportKey !== null) {
            return self::$lastReportKey;
        }

        if (! extension_loaded('spx') || ! function_exists('spx_profiler_stop')) {
            return null;
        }

        if (! self::$started && ! self::isEnabled()) {
            return null;
        }

        try {
            $key = spx_profiler_stop();
            if (is_string($key) && $key !== '') {
                self::$lastReportKey = $key;
            }
        } catch (\Throwable) {
            // Ignore — fall back to data-dir scan / control-panel link.
        }

        self::$started = false;

        return self::$lastReportKey;
    }

    public static function lastReportKey(): ?string
    {
        return self::$lastReportKey;
    }

    public static function isStarted(): bool
    {
        return self::$started;
    }

    public static function isEnabled(): bool
    {
        if (self::autoEnable()) {
            return true;
        }

        // Request-scoped opt-in only. Do not treat process env SPX_ENABLED as a
        // reason to profile — FPM often sets that so the programmatic API works
        // while spx.http_profiling_auto_start=0 (idle extension, no sampling).
        foreach ([
            $_COOKIE['SPX_ENABLED'] ?? null,
            $_GET['SPX_ENABLED'] ?? null,
        ] as $value) {
            if ($value !== false && $value !== null && $value !== '' && $value !== '0') {
                return true;
            }
        }

        return false;
    }

    public static function autoEnable(): bool
    {
        try {
            return (bool) config('context-logging.profiling.spx.auto_enable', false);
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * Copy SPX_* cookie/query params into the environment for the C extension.
     */
    public static function mirrorHttpParamsToEnv(): void
    {
        foreach (['SPX_ENABLED', 'SPX_REPORT', 'SPX_METRICS', 'SPX_BUILTINS', 'SPX_DEPTH', 'SPX_SAMPLING_PERIOD', 'SPX_AUTO_START'] as $key) {
            $value = $_COOKIE[$key] ?? $_GET[$key] ?? null;
            if ($value === null || $value === '') {
                continue;
            }
            if (getenv($key) === false || getenv($key) === '') {
                putenv($key.'='.$value);
                $_ENV[$key] = $value;
            }
        }

        if (self::autoEnable()) {
            if (getenv('SPX_ENABLED') === false || getenv('SPX_ENABLED') === '' || getenv('SPX_ENABLED') === '0') {
                putenv('SPX_ENABLED=1');
                $_ENV['SPX_ENABLED'] = '1';
            }

            // Prefer programmatic start/stop so emit-time stop() returns a report key.
            putenv('SPX_AUTO_START=0');
            $_ENV['SPX_AUTO_START'] = '0';
        }

        // Default to full reports so stop() can return a UI key.
        if (getenv('SPX_REPORT') === false || getenv('SPX_REPORT') === '') {
            putenv('SPX_REPORT=full');
            $_ENV['SPX_REPORT'] = 'full';
        }
    }
}
