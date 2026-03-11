<?php

declare(strict_types=1);

namespace Michael4d45\ContextLogging;

use Illuminate\Http\Request;
use Illuminate\Support\Str;

/**
 * Helpers for request/response logging: sensitive data masking and route filtering.
 */
final class LoggingHelper
{
    /**
     * Whether the request should be ignored for request/response logging.
     */
    public static function shouldIgnoreRoute(?Request $request): bool
    {
        if ($request === null) {
            return true;
        }

        $patterns = config('context-logging.log.ignore_routes', []);
        if ($patterns === []) {
            return false;
        }

        $routeName = $request->route()?->getName();
        $path = $request->path();

        foreach ($patterns as $pattern) {
            $pattern = trim((string) $pattern);
            if ($pattern === '') {
                continue;
            }
            if (Str::is($pattern, $routeName ?? '') || Str::is($pattern, $path)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Mask header values for keys listed in config (e.g. authorization, cookie).
     *
     * @param array<string, array<int, string>> $headers
     * @return array<string, array<int, string>>
     */
    public static function maskHeaders(array $headers): array
    {
        $redact = array_map('strtolower', config('context-logging.http.redact_headers', []));
        $value = config('context-logging.http.redact_value', '[redacted]');

        $result = [];
        foreach ($headers as $name => $values) {
            $result[$name] = in_array(strtolower($name), $redact, true)
                ? [$value]
                : $values;
        }

        return $result;
    }

    /**
     * Recursively mask array keys that match configured sensitive field names.
     *
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    public static function maskSensitiveData(array $data): array
    {
        $keys = config('context-logging.http.redact_body_fields', []);
        $value = config('context-logging.http.redact_value', '[redacted]');
        $queryKeys = config('context-logging.http.redact_query_params', []);
        $allKeys = array_unique(array_merge($keys, $queryKeys));

        return self::maskRecursive($data, $allKeys, $value);
    }

    /**
     * @param array<string, mixed> $data
     * @param array<int, string> $sensitiveKeys
     * @return array<string, mixed>
     */
    private static function maskRecursive(array $data, array $sensitiveKeys, string $redactValue): array
    {
        $result = [];
        foreach ($data as $k => $v) {
            $keyLower = strtolower((string) $k);
            $match = false;
            foreach ($sensitiveKeys as $s) {
                if ($keyLower === strtolower($s) || Str::contains($keyLower, strtolower($s))) {
                    $match = true;
                    break;
                }
            }
            if ($match) {
                $result[$k] = $redactValue;
                continue;
            }
            $result[$k] = is_array($v) ? self::maskRecursive($v, $sensitiveKeys, $redactValue) : $v;
        }
        return $result;
    }

    /**
     * Mask cookie values (all values redacted by default for logging).
     *
     * @param array<string, string> $cookies
     * @return array<string, string>
     */
    public static function maskCookies(array $cookies): array
    {
        $value = config('context-logging.http.redact_value', '[redacted]');
        $result = [];
        foreach ($cookies as $name => $cookieValue) {
            $result[$name] = $value;
        }
        return $result;
    }
}
