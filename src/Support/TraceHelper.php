<?php

declare(strict_types=1);

namespace Michael4d45\ContextLogging\Support;

/**
 * Builds a collapsed stack trace relative to the application, excluding vendor and framework noise.
 *
 * @return array<int, string>
 */
final class TraceHelper
{
    public static function getCollapsedTrace(): array
    {
        $trace = debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS);
        $basePath = str_replace('\\', '/', (string) (function_exists('base_path') ? base_path() : ''));
        $vendorPath = $basePath !== '' ? $basePath . '/vendor' : '';

        $lines = [];

        foreach ($trace as $frame) {
            $file = isset($frame['file']) ? str_replace('\\', '/', $frame['file']) : null;
            $line = $frame['line'] ?? 0;

            if ($file === null) {
                continue;
            }

            if (str_contains($file, 'storage/framework/views')) {
                $original = self::originalBladeFromCompiled($file);
                $file = $original ?? $file;
                $line = $original !== null ? 0 : $line;
            }

            if ($vendorPath !== '' && str_starts_with($file, $vendorPath)) {
                continue;
            }

            if ($file === 'artisan') {
                continue;
            }

            if (str_contains($file, 'public/index.php')) {
                continue;
            }

            if (str_contains($file, 'ContextLogging') || str_contains($file, 'ServiceProvider')) {
                continue;
            }

            $relativeFile = $basePath !== '' && str_starts_with($file, $basePath . '/')
                ? substr($file, strlen($basePath) + 1)
                : $file;

            if (str_contains($relativeFile, 'storage/framework/views/') && is_readable($file)) {
                $handle = @fopen($file, 'r');
                if ($handle !== false) {
                    $firstLine = fgets($handle);
                    fclose($handle);
                    if (
                        $firstLine !== false
                        && preg_match('/content:\s*(.+?\.blade\.php)/', $firstLine, $matches)
                    ) {
                        $resolved = $matches[1];
                        $relativeFile = (str_starts_with($resolved, $basePath . '/'))
                            ? substr($resolved, strlen($basePath) + 1) . ' (compiled)'
                            : $resolved . ' (compiled)';
                    }
                }
            }

            $lines[] = "{$relativeFile}:{$line}";
        }

        return $lines;
    }

    private static function originalBladeFromCompiled(string $compiledFile): ?string
    {
        if (!is_file($compiledFile)) {
            return null;
        }

        $contents = @file_get_contents($compiledFile);
        if ($contents === false) {
            return null;
        }

        if (preg_match('/\/\*\*PATH\s+(.*?)\s+ENDPATH\*\*\//', $contents, $m)) {
            return trim($m[1]);
        }

        return null;
    }
}
