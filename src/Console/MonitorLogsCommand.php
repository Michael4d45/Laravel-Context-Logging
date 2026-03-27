<?php

declare(strict_types=1);

namespace Michael4d45\ContextLogging\Console;

use Illuminate\Console\Command;

class MonitorLogsCommand extends Command
{
    /**
     * The name and signature of the console command.
     */
    protected $signature = 'log:monitor
                            {file? : The path to the log file to monitor}
                            {--lines=0 : Number of recent lines to read on start (0 = tail only)}
                            {--threshold=50 : Skip batch if new lines exceed this (0 = no limit)}
                            {--auto-truncate= : Max size before truncating to half (e.g. 10MB, 1GB)}
                            {--json-indent=2 : Number of spaces per JSON indent level (0 = compact)}';

    /**
     * The console command description.
     */
    protected $description = 'Tail and pretty-print JSON-formatted context logs';

    protected int $skipThreshold = 50;

    protected int $sqlQueryCount = 0;

    /** @var int|null Max size in bytes; when file exceeds this, truncate to half (null = disabled) */
    protected ?int $autoTruncateBytes = null;

    /** @var int Number of spaces to use for JSON indentation per level */
    protected int $jsonIndentSpaces = 2;

    public function handle(): int
    {
        $file = $this->argument('file') ?? storage_path('logs/laravel.log');
        $this->skipThreshold = (int) $this->option('threshold');
        $jsonIndentOpt = $this->option('json-indent');
        $validatedIndent = filter_var($jsonIndentOpt, FILTER_VALIDATE_INT);
        if ($validatedIndent === false || $validatedIndent < 0) {
            $this->error("Invalid --json-indent value: '{$jsonIndentOpt}'. Must be a non-negative integer.");

            return self::FAILURE;
        }
        $this->jsonIndentSpaces = (int) $validatedIndent;
        $autoTruncate = $this->option('auto-truncate');
        if ($autoTruncate !== null && $autoTruncate !== '') {
            $this->autoTruncateBytes = $this->parseSizeToBytes((string) $autoTruncate);
            if ($this->autoTruncateBytes === null || $this->autoTruncateBytes <= 0) {
                $this->error("Invalid --auto-truncate value: '{$autoTruncate}'. Use e.g. 10MB, 1GB, 500KB.");

                return self::FAILURE;
            }
        }

        if (!is_file($file)) {
            $this->error("The log file does not exist: '{$file}'");

            return self::FAILURE;
        }

        $this->info("Monitoring logs: {$file}");
        $this->line('Press Ctrl+C to exit.' . PHP_EOL);

        $handle = fopen($file, 'r');
        if ($handle === false) {
            $this->error('Could not open log file.');

            return self::FAILURE;
        }

        $initialLines = (int) $this->option('lines');
        if ($initialLines > 0) {
            $this->readInitialLines($handle, $file, $initialLines);
        } else {
            fseek($handle, 0, SEEK_END);
        }

        $lastPosition = ftell($handle);

        while (true) {
            clearstatcache(false, $file);
            $currentSize = filesize($file);
            if ($currentSize === false) {
                usleep(500000);
                continue;
            }

            if ($this->autoTruncateBytes !== null && $currentSize >= $this->autoTruncateBytes) {
                fclose($handle);
                $newSize = $this->truncateLogFileToHalf($file, $currentSize);
                if ($newSize === null) {
                    $this->error('Failed to truncate log file.');

                    return self::FAILURE;
                }
                $this->warn("Log file exceeded {$this->formatBytes($this->autoTruncateBytes)}. Truncated to half: " . $this->formatBytes($newSize) . '.');
                $handle = fopen($file, 'r');
                if ($handle === false) {
                    return self::FAILURE;
                }
                fseek($handle, 0, SEEK_END);
                $lastPosition = ftell($handle);
                continue;
            }

            if ($currentSize < $lastPosition) {
                fclose($handle);
                $handle = fopen($file, 'r');
                if ($handle === false) {
                    return self::FAILURE;
                }
                fseek($handle, 0, SEEK_END);
                $lastPosition = ftell($handle);
                $this->warn('Log file was rotated or truncated. Restarting tail...');
                continue;
            }

            if ($currentSize > $lastPosition) {
                fseek($handle, $lastPosition);
                $newLines = $this->readLines($handle);
                $lastPosition = ftell($handle);
                $this->processLines($newLines);
            }

            usleep(500000);
        }
    }

    protected function readInitialLines($handle, string $file, int $lineCount): void
    {
        $size = filesize($file);
        if ($size === false || $size === 0) {
            return;
        }
        $chunk = (int) min($size, 512 * 1024);
        fseek($handle, max(0, $size - $chunk));
        if (ftell($handle) > 0) {
            fgets($handle);
        }
        $buffer = stream_get_contents($handle);
        $lines = $buffer !== false ? explode("\n", $buffer) : [];
        $lines = array_slice(array_filter($lines, fn (string $l) => trim($l) !== ''), -$lineCount);
        $this->processLines($lines);
        fseek($handle, 0, SEEK_END);
    }

    /**
     * Parse a size string (e.g. "10MB", "1GB", "500KB") to bytes. Returns null if invalid.
     */
    protected function parseSizeToBytes(string $value): ?int
    {
        $value = trim($value);
        if ($value === '') {
            return null;
        }
        if (preg_match('/^(\d+(?:\.\d+)?)\s*([KMGTP]?B?)$/i', $value, $m)) {
            $num = (float) $m[1];
            $unit = strtoupper($m[2]);
            $mult = match ($unit) {
                'B', '' => 1,
                'KB', 'K' => 1024,
                'MB', 'M' => 1024 * 1024,
                'GB', 'G' => 1024 * 1024 * 1024,
                'TB', 'T' => 1024 * 1024 * 1024 * 1024,
                'PB', 'P' => 1024 * 1024 * 1024 * 1024 * 1024,
                default => null,
            };
            if ($mult !== null && $num >= 0) {
                return (int) round($num * $mult);
            }
        }

        return null;
    }

    /**
     * Truncate the log file to half its size, keeping the most recent half. Returns new size in bytes or null on failure.
     */
    protected function truncateLogFileToHalf(string $file, int $currentSize): ?int
    {
        $half = (int) floor($currentSize / 2);
        if ($half <= 0) {
            return 0;
        }
        $r = fopen($file, 'r');
        if ($r === false) {
            return null;
        }
        if (fseek($r, $currentSize - $half, SEEK_SET) !== 0) {
            fclose($r);

            return null;
        }
        $content = stream_get_contents($r);
        fclose($r);
        if ($content === false || strlen($content) !== $half) {
            return null;
        }
        $w = fopen($file, 'wb');
        if ($w === false) {
            return null;
        }
        $written = fwrite($w, $content);
        fclose($w);
        if ($written !== $half) {
            return null;
        }

        return $half;
    }

    protected function formatBytes(int $bytes): string
    {
        if ($bytes >= 1073741824) {
            return round($bytes / 1073741824, 1) . 'GB';
        }
        if ($bytes >= 1048576) {
            return round($bytes / 1048576, 1) . 'MB';
        }
        if ($bytes >= 1024) {
            return round($bytes / 1024, 1) . 'KB';
        }

        return $bytes . 'B';
    }

    /**
     * @return array<int, string>
     */
    protected function readLines($handle): array
    {
        $lines = [];
        while (($line = fgets($handle)) !== false) {
            $trimmed = trim($line);
            if ($trimmed !== '') {
                $lines[] = $trimmed;
            }
        }

        return $lines;
    }

    /**
     * @param array<int, string> $lines
     */
    protected function processLines(array $lines): void
    {
        $count = count($lines);

        if ($this->skipThreshold > 0 && $count > $this->skipThreshold) {
            $this->warn("Skipped {$count} log line(s) (exceeded threshold of {$this->skipThreshold}).");

            return;
        }

        foreach ($lines as $line) {
            $data = json_decode($line, true);

            if (json_last_error() === JSON_ERROR_NONE && is_array($data)) {
                $this->formatLogEntry($data);
            } else {
                $this->line('<fg=gray>' . $this->escapeLine($line) . '</>');
            }
        }
    }

    protected function escapeLine(string $line): string
    {
        return str_replace(['<', '>'], ['\\<', '\\>'], $line);
    }

    /**
     * Output syntax-highlighted JSON (monokai-like style) as lines. Pass prefix to indent (e.g. "  <fg=gray>│</>   ").
     *
     * @param string|array<int|string, mixed> $json JSON string or decoded array
     * @return array<int, string> Lines with Symfony Console color tags
     */
    protected function colorizeJson(string|array $json): array
    {
        if (is_array($json)) {
            $decoded = $json;
        } else {
            $decoded = json_decode($json, true);
            if (json_last_error() !== JSON_ERROR_NONE || !is_array($decoded)) {
                return [$this->escapeLine($json)];
            }
        }

        return $this->colorizeJsonLines($decoded, 0);
    }

    /**
     * @param array<int|string, mixed> $data
     * @return array<int, string>
     */
    private function colorizeJsonLines(array $data, int $depth): array
    {
        $lines = [];
        $indentUnit = $this->getJsonIndentUnit();
        $indent = str_repeat($indentUnit, $depth);
        $childIndent = str_repeat($indentUnit, $depth + 1);

        $isList = !$this->isAssoc($data);

        if ($data === []) {
            return [$indent . '<fg=gray>' . ($isList ? '[]' : '{}') . '</>'];
        }

        $open = $isList ? '[' : '{';
        $close = $isList ? ']' : '}';

        $lines[] = $indent . "<fg=gray>{$open}</>";

        $lastKey = array_key_last($data);

        foreach ($data as $key => $value) {
            $isLast = ($key === $lastKey);
            $comma = $isLast ? '' : '<fg=gray>,</>';

            if ($isList) {
                $valueLines = $this->colorizeJsonValue($value, $depth + 1);

                if (is_array($valueLines)) {
                    foreach ($valueLines as $i => $line) {
                        $lines[] = $line;
                    }
                    $lines[array_key_last($lines)] .= $comma;
                } else {
                    $lines[] = $childIndent . $valueLines . $comma;
                }
            } else {
                $keyEsc = $this->escapeLine($this->escapeJsonString((string) $key));
                $keyPart = '<fg=#e6db74>"' . $keyEsc . '"</><fg=gray>: </>';

                $valueLines = $this->colorizeJsonValue($value, $depth + 1);

                if (is_array($valueLines)) {
                    // First line attaches to key
                    $first = array_shift($valueLines);
                    $lines[] = $childIndent . $keyPart . ltrim($first);

                    // Remaining lines already correctly indented
                    foreach ($valueLines as $line) {
                        $lines[] = $line;
                    }

                    $lines[array_key_last($lines)] .= $comma;
                } else {
                    $lines[] = $childIndent . $keyPart . $valueLines . $comma;
                }
            }
        }

        $lines[] = $indent . "<fg=gray>{$close}</>";

        return $lines;
    }

    /**
     * @return string|array<int, string> Single line or multiple lines (first line + continuation)
     */
    private function colorizeJsonValue(mixed $value, int $depth): string|array
    {
        if (is_array($value)) {
            return $this->colorizeJsonLines($value, $depth);
        }

        if (is_string($value)) {
            $esc = $this->escapeLine($this->escapeJsonString($value));
            return '<fg=#e6db74>"</><fg=#a6e22e>' . $esc . '</><fg=#e6db74>"</>';
        }

        if (is_int($value) || is_float($value)) {
            return '<fg=#ae81ff>' . (string) $value . '</>';
        }

        if (is_bool($value)) {
            return '<fg=#ae81ff>' . ($value ? 'true' : 'false') . '</>';
        }

        if ($value === null) {
            return '<fg=gray>null</>';
        }

        $encoded = json_encode($value);
        return $encoded !== false ? $this->escapeLine($encoded) : '<fg=gray>null</>';
    }

    private function escapeJsonString(string $s): string
    {
        return str_replace(
            ['\\', '"', "\n", "\r", "\t"],
            ['\\\\', '\\"', '\\n', '\\r', '\\t'],
            $s
        );
    }

    private function getJsonIndentUnit(): string
    {
        if ($this->jsonIndentSpaces <= 0) {
            return '';
        }

        return str_repeat(' ', $this->jsonIndentSpaces);
    }

    protected function renderPrefixedLine(string $prefix, string $line): string
    {
        return rtrim($prefix) . ' ' . $line;
    }

    /**
     * Output a formatted URL breakdown (base, path, query params) with colors.
     */
    protected function formatUrl(string $url, string $barColor = 'gray'): void
    {
        $url = trim($url);
        if ($url === '' || $url === 'null') {
            return;
        }

        if (!preg_match('#^https?://#', $url)) {
            $this->line($this->renderPrefixedLine("  <fg={$barColor}>│</>", $this->escapeLine($url)));

            return;
        }

        $parsed = parse_url($url);
        $scheme = $parsed['scheme'] ?? 'https';
        $host = $parsed['host'] ?? '';
        $port = isset($parsed['port']) ? ':' . $parsed['port'] : '';
        $base = $scheme . '://' . $host . $port;
        $path = $parsed['path'] ?? '/';
        $query = $parsed['query'] ?? null;

        $this->line($this->renderPrefixedLine("  <fg={$barColor}>│</>", "<fg=#eab308>Base:</> <fg=#0ea5e9>" . $this->escapeLine($base) . "</>"));
        $this->line($this->renderPrefixedLine("  <fg={$barColor}>│</>", "<fg=#eab308>Path:</> <fg=#a78bfa>" . $this->escapeLine($path) . "</>"));

        if ($query !== null && $query !== '') {
            $this->line($this->renderPrefixedLine("  <fg={$barColor}>│</>", "<fg=#eab308>Query Params:</>"));
            foreach (explode('&', $query) as $param) {
                $eq = strpos($param, '=');
                if ($eq !== false) {
                    $key = substr($param, 0, $eq);
                    $value = substr($param, $eq + 1);
                    $value = rawurldecode($value);
                    $this->line($this->renderPrefixedLine("  <fg={$barColor}>│</>", "    <fg=#06b6d4>" . $this->escapeLine($key) . "</> <fg=#6b7280>=</> <fg=white>" . $this->escapeLine($value) . "</>"));
                } else {
                    $this->line($this->renderPrefixedLine("  <fg={$barColor}>│</>", "    <fg=white>" . $this->escapeLine($param) . "</>"));
                }
            }
        }
    }

    protected function isUrl(string $value): bool
    {
        return preg_match('#^https?://[^\s]+$#', trim($value)) === 1;
    }

    protected function formatLogEntry(array $entry): void
    {
        $level = $entry['level_name'] ?? 'UNKNOWN';
        $message = $entry['message'] ?? 'No message';
        $timestamp = $entry['datetime'] ?? ($entry['timestamp'] ?? '');
        $context = $entry['context'] ?? [];

        $levelColor = $this->levelColor($entry['level'] ?? 200);
        $this->line('');
        $this->line("<bg={$levelColor};fg=white;options=bold> {$level} </> <fg=#6b7280>{$timestamp}</> <fg=white>{$message}</>");

        $ctx = $context['context'] ?? null;
        $events = $context['events'] ?? null;

        if (is_array($ctx) && $ctx !== []) {
            $this->formatContextBlock($ctx);
        }

        if (is_array($events) && $events !== []) {
            $this->processTimelineEvents($events, $ctx ?? []);
        }

        $this->line('<fg=#374151>──────────────────────────────────────────────────────────────────────────────</>');
    }

    protected function levelColor(int $level): string
    {
        return match (true) {
            $level >= 500 => 'red',
            $level >= 400 => 'red',
            $level >= 300 => 'yellow',
            $level >= 200 => 'blue',
            default => 'gray',
        };
    }

    /**
     * @param array<string, mixed> $ctx
     */
    protected function formatContextBlock(array $ctx): void
    {
        $this->line('  <fg=#60a5fa>┌─ <options=bold>Context</></>');

        $keys = ['request_id', 'run_id', 'method', 'path', 'full_url', 'ip', 'duration_ms', 'status', 'command'];
        foreach ($keys as $key) {
            if (!array_key_exists($key, $ctx)) {
                continue;
            }
            $value = $ctx[$key];
            if ($key === 'full_url' && is_scalar($value) && $this->isUrl((string) $value)) {
                    $this->line($this->renderPrefixedLine('  <fg=#60a5fa>│</>', '<fg=#eab308;options=bold>full_url</>:'));
                    $this->formatUrl((string) $value, '#60a5fa');
                continue;
            }
            if (is_array($value)) {
                    $this->line($this->renderPrefixedLine('  <fg=#60a5fa>│</>', '<fg=#eab308;options=bold>' . $key . '</>:'));
                    $this->outputColorizedJson($this->colorizeJson($value), '  <fg=#60a5fa>│</>');
                continue;
            }
            $display = is_scalar($value) ? (string) $value : json_encode($value);
            $valColor = $key === 'status' ? $this->statusCodeColor((int) $value) : 'white';
            $this->line($this->renderPrefixedLine('  <fg=#60a5fa>│</>', '<fg=#eab308;options=bold>' . $key . '</>: <fg=' . $valColor . '>' . $this->escapeLine($display) . '</>'));
        }

        $rest = array_diff_key($ctx, array_flip($keys));
        if ($rest !== []) {
            foreach ($rest as $key => $value) {
                if (is_array($value)) {
                    $this->line($this->renderPrefixedLine('  <fg=#60a5fa>│</>', '<fg=#eab308;options=bold>' . $key . '</>:'));
                    $this->outputColorizedJson($this->colorizeJson($value), '  <fg=#60a5fa>│</>');
                    continue;
                }
                $display = is_scalar($value) ? (string) $value : json_encode($value);
                $this->line($this->renderPrefixedLine('  <fg=#60a5fa>│</>', '<fg=#eab308;options=bold>' . $key . '</>: <fg=white>' . $this->escapeLine($display) . '</>'));
            }
        }

        $this->line('  <fg=#60a5fa>└─</>');
    }

    protected function statusCodeColor(int $code): string
    {
        return match (true) {
            $code >= 500 => '#ef4444',
            $code >= 400 => '#f97316',
            $code >= 300 => '#eab308',
            $code >= 200 => '#22c55e',
            default => 'white',
        };
    }

    /**
     * @param array<int, string> $lines Lines that may contain Symfony Console tags
     */
    protected function outputColorizedJson(array $lines, string $prefix): void
    {
        foreach ($lines as $line) {
            $this->line($this->renderPrefixedLine($prefix, $line));
        }
    }

    /**
     * @param array<int, array{message?: string, context?: array<string, mixed>, level?: string, timestamp?: float}> $events
     * @param array<string, mixed> $mainContext
     */
    protected function processTimelineEvents(array $events, array $mainContext = []): void
    {
        $this->sqlQueryCount = 0;
        usort($events, fn ($a, $b) => ($a['timestamp'] ?? 0) <=> ($b['timestamp'] ?? 0));

        foreach ($events as $event) {
            $msg = $event['message'] ?? '';
            $ctx = $event['context'] ?? [];

            match (true) {
                $msg === 'sql' => $this->formatSqlQuery($ctx),
                $msg === 'Incoming Request' => $this->formatIncomingRequest($ctx, $mainContext),
                $msg === 'Outgoing Response' => $this->formatOutgoingResponse($ctx),
                $msg === 'User' => $this->formatUser($ctx),
                $msg === 'cache' => $this->formatCacheEvent($ctx),
                $msg === 'queue' => $this->formatQueueEvent($ctx),
                $msg === 'HTTP Call' => $this->formatHttpCall($ctx),
                default => $this->formatGenericEvent($msg, $ctx),
            };
        }
    }

    /**
     * @param array<string, mixed> $context
     */
    protected function formatSqlQuery(array $context): void
    {
        $sql = $context['SQL'] ?? '';
        $time = $context['execution_time'] ?? '0';

        $this->sqlQueryCount++;
        $timeStr = (string) $time;
        $ms = (float) preg_replace('/[^0-9.]/', '', $timeStr);
        if (stripos($timeStr, 's') !== false && stripos($timeStr, 'ms') === false) {
            $ms *= 1000;
        }
        $timeColor = $ms > 100 ? '#ef4444' : ($ms > 20 ? '#eab308' : '#22c55e');
        $this->line('  <fg=#06b6d4>┌─ <options=bold>[SQL]</></> <fg=' . $timeColor . '>' . $this->escapeLine((string) $time) . '</> <fg=#6b7280>(#' . $this->sqlQueryCount . ')</>');

        if (class_exists(\Doctrine\SqlFormatter\SqlFormatter::class) && class_exists(\Doctrine\SqlFormatter\CliHighlighter::class)) {
            $formatter = new \Doctrine\SqlFormatter\SqlFormatter(new \Doctrine\SqlFormatter\CliHighlighter());
            $formatted = $formatter->format($sql);
            foreach (explode("\n", $formatted) as $formattedLine) {
                    $this->line($this->renderPrefixedLine('  <fg=#06b6d4>│</>', $formattedLine));
            }
        } else {
            foreach (explode("\n", $sql) as $sqlLine) {
                    $this->line($this->renderPrefixedLine('  <fg=#06b6d4>│</>', '<fg=#e5e7eb>' . $this->escapeLine($sqlLine) . '</>'));
            }
        }

        $trace = $context['trace'] ?? null;
        if (is_array($trace) && $trace !== []) {
            $this->line($this->renderPrefixedLine('  <fg=#06b6d4>│</>', '<fg=#6b7280>Trace:</>'));
            foreach (array_slice($trace, 0, 5) as $frame) {
                $this->line($this->renderPrefixedLine('  <fg=#06b6d4>│</>', '    <fg=#9ca3af>' . $this->escapeLine((string) $frame) . '</>'));
            }
        }

        $this->line('  <fg=#06b6d4>└─</>');
    }

    /**
     * @param array<string, mixed> $context Event context (body, query_params, headers, cookies)
     * @param array<string, mixed> $mainContext Request context (method, full_url, ip, user_agent)
     */
    protected function formatIncomingRequest(array $context, array $mainContext = []): void
    {
        $method = $context['method'] ?? $mainContext['method'] ?? 'GET';
        $url = $context['url'] ?? $mainContext['full_url'] ?? '';
        $ip = $context['ip'] ?? $mainContext['ip'] ?? null;
        $userAgent = $context['user_agent'] ?? $mainContext['user_agent'] ?? null;
        $body = $context['body'] ?? [];
        $queryParams = $context['query_params'] ?? [];
        $headers = $context['headers'] ?? [];

        $methodColor = in_array(strtoupper((string) $method), ['GET', 'HEAD'], true) ? '#22c55e' : (strtoupper((string) $method) === 'POST' ? '#3b82f6' : '#a855f7');
        $this->line('  <fg=#16a34a>┌─ <options=bold>Incoming Request</></>');
        $this->line($this->renderPrefixedLine('  <fg=#16a34a>│</>', '<fg=' . $methodColor . ';options=bold>' . $this->escapeLine((string) $method) . '</> <fg=#0ea5e9>' . $this->escapeLine((string) $url) . '</>'));
        if ($url !== '' && $this->isUrl($url)) {
            $this->formatUrl($url, '#16a34a');
        }
        if ($ip !== null && $ip !== '') {
            $this->line($this->renderPrefixedLine('  <fg=#16a34a>│</>', '<fg=#eab308>IP:</> <fg=white>' . $this->escapeLine((string) $ip) . '</>'));
        }
        if ($userAgent !== null && $userAgent !== '') {
            $this->line($this->renderPrefixedLine('  <fg=#16a34a>│</>', '<fg=#eab308>User-Agent:</> <fg=#9ca3af>' . $this->escapeLine((string) $userAgent) . '</>'));
        }

        if (is_array($body) && $body !== []) {
            $this->line($this->renderPrefixedLine('  <fg=#16a34a>│</>', '<fg=#eab308;options=bold>Body:</>'));
            $colored = $this->colorizeJson($body);
            $this->outputColorizedJson($colored, '  <fg=#16a34a>│</>');
        } elseif (!is_array($body) && (string) $body !== '') {
            $bodyStr = (string) $body;
            $decoded = json_decode($bodyStr, true);
            if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
                $this->line($this->renderPrefixedLine('  <fg=#16a34a>│</>', '<fg=#eab308;options=bold>Body:</>'));
                $this->outputColorizedJson($this->colorizeJson($decoded), '  <fg=#16a34a>│</>');
            } else {
                $this->line($this->renderPrefixedLine('  <fg=#16a34a>│</>', '<fg=#eab308>Body:</> <fg=white>' . $this->escapeLine($bodyStr) . '</>'));
            }
        }

        if (is_array($queryParams) && $queryParams !== []) {
            $this->line($this->renderPrefixedLine('  <fg=#16a34a>│</>', '<fg=#eab308;options=bold>Query:</>'));
            $this->formatNestedArray('  <fg=#16a34a>│</>', $queryParams);
        }

        if (is_array($headers) && $headers !== []) {
            $this->line($this->renderPrefixedLine('  <fg=#16a34a>│</>', '<fg=#eab308;options=bold>Headers:</>'));
            $this->formatNestedArray('  <fg=#16a34a>│</>', $this->flattenHeaders($headers));
        }

        $this->line('  <fg=#16a34a>└─</>');
    }

    /**
     * @param array<string, mixed> $context
     */
    protected function formatOutgoingResponse(array $context): void
    {
        $status = $context['status_code'] ?? null;
        $redirectTarget = $context['redirect_target'] ?? null;
        $contentType = $context['content_type'] ?? null;
        $contentLength = $context['content_length'] ?? null;
        $body = $context['body'] ?? null;
        $headers = $context['headers'] ?? [];

        $statusInt = $status !== null ? (int) $status : 0;
        $statusColor = $this->statusCodeColor($statusInt);
        $this->line('  <fg=#c026d3>┌─ <options=bold>Outgoing Response</></>');
        if ($status !== null) {
            $this->line($this->renderPrefixedLine('  <fg=#c026d3>│</>', '<fg=#eab308>Status:</> <fg=' . $statusColor . ';options=bold>' . $this->escapeLine((string) $status) . '</>'));
        }
        if ($redirectTarget !== null && $redirectTarget !== '') {
            $this->line($this->renderPrefixedLine('  <fg=#c026d3>│</>', '<fg=#eab308>Redirect:</> <fg=#0ea5e9>' . $this->escapeLine((string) $redirectTarget) . '</>'));
        }
        if ($contentType !== null && $contentType !== '') {
            $this->line($this->renderPrefixedLine('  <fg=#c026d3>│</>', '<fg=#eab308>Content-Type:</> <fg=white>' . $this->escapeLine((string) $contentType) . '</>'));
        }
        if ($contentLength !== null) {
            $this->line($this->renderPrefixedLine('  <fg=#c026d3>│</>', '<fg=#eab308>Content-Length:</> <fg=#a78bfa>' . $this->escapeLine((string) $contentLength) . '</>'));
        }

        if (is_array($body) && $body !== []) {
            $this->line($this->renderPrefixedLine('  <fg=#c026d3>│</>', '<fg=#eab308;options=bold>Body:</>'));
            $colored = $this->colorizeJson($body);
            $this->outputColorizedJson($colored, '  <fg=#c026d3>│</>');
        } elseif (is_string($body) && $body !== '') {
            $decoded = json_decode($body, true);
            if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
                $this->line($this->renderPrefixedLine('  <fg=#c026d3>│</>', '<fg=#eab308;options=bold>Body:</>'));
                $colored = $this->colorizeJson($decoded);
                $this->outputColorizedJson($colored, '  <fg=#c026d3>│</>');
            } else {
                $preview = strlen($body) > 500 ? substr($body, 0, 500) . '…' : $body;
                foreach (explode("\n", $preview) as $bodyLine) {
                    $this->line($this->renderPrefixedLine('  <fg=#c026d3>│</>', '<fg=#e5e7eb>' . $this->escapeLine($bodyLine) . '</>'));
                }
            }
        }

        if (is_array($headers) && $headers !== []) {
            $this->line($this->renderPrefixedLine('  <fg=#c026d3>│</>', '<fg=#eab308;options=bold>Headers:</>'));
            $this->formatNestedArray('  <fg=#c026d3>│</>', $this->flattenHeaders($headers));
        }

        $this->line('  <fg=#c026d3>└─</>');
    }

    /**
     * @param array<string, mixed> $context
     */
    /**
     * @param array<string, mixed> $context User event context (keys from config context-logging.log.user_attributes + timestamp)
     */
    protected function formatUser(array $context): void
    {
        $this->line('  <fg=#eab308>┌─ <options=bold>User</></>');
        foreach ($context as $key => $value) {
            if ($value === null) {
                continue;
            }
            $display = is_bool($value) ? ($value ? 'true' : 'false') : $this->escapeLine((string) $value);
            $this->line($this->renderPrefixedLine('  <fg=#eab308>│</>', '<fg=#06b6d4>' . $this->escapeLine((string) $key) . '</>: <fg=white>' . $display . '</>'));
        }
        $this->line('  <fg=#eab308>└─</>');
    }

    /**
     * @param array<string, mixed> $context
     */
    protected function formatCacheEvent(array $context): void
    {
        $event = $context['event'] ?? 'cache';
        $key = $context['key'] ?? '';
        $expiration = $context['expiration'] ?? null;

        $this->line('  <fg=#3b82f6>┌─ <options=bold>Cache</></> <fg=#a78bfa>' . $this->escapeLine((string) $event) . '</> <fg=#eab308>key:</> <fg=white>' . $this->escapeLine((string) $key) . '</>');
        if ($expiration !== null) {
            $this->line($this->renderPrefixedLine('  <fg=#3b82f6>│</>', '<fg=#eab308>expiration:</> <fg=#22c55e>' . $this->escapeLine((string) $expiration) . 's</>'));
        }
        $trace = $context['trace'] ?? null;
        if (is_array($trace) && $trace !== []) {
            $this->line($this->renderPrefixedLine('  <fg=#3b82f6>│</>', '<fg=#6b7280>Trace:</>'));
            foreach (array_slice($trace, 0, 3) as $frame) {
                $this->line($this->renderPrefixedLine('  <fg=#3b82f6>│</>', '    <fg=#9ca3af>' . $this->escapeLine((string) $frame) . '</>'));
            }
        }
        $this->line('  <fg=#3b82f6>└─</>');
    }

    /**
     * @param array<string, mixed> $context
     */
    protected function formatQueueEvent(array $context): void
    {
        $event = $context['event'] ?? 'queue';
        $job = $context['job'] ?? '';
        $queue = $context['queue'] ?? null;
        $connection = $context['connection'] ?? null;
        $attempts = $context['attempts'] ?? null;
        $exception = $context['exception'] ?? null;
        $size = $context['size'] ?? null;

        $this->line('  <fg=#f97316>┌─ <options=bold>Queue</></> <fg=#a78bfa>' . $this->escapeLine((string) $event) . '</>');
        $this->line($this->renderPrefixedLine('  <fg=#f97316>│</>', '<fg=#eab308>job:</> <fg=white>' . $this->escapeLine((string) $job) . '</>'));
        if ($queue !== null) {
            $this->line($this->renderPrefixedLine('  <fg=#f97316>│</>', '<fg=#eab308>queue:</> <fg=#06b6d4>' . $this->escapeLine((string) $queue) . '</>'));
        }
        if ($connection !== null) {
            $this->line($this->renderPrefixedLine('  <fg=#f97316>│</>', '<fg=#eab308>connection:</> <fg=white>' . $this->escapeLine((string) $connection) . '</>'));
        }
        if ($attempts !== null) {
            $this->line($this->renderPrefixedLine('  <fg=#f97316>│</>', '<fg=#eab308>attempts:</> <fg=#a78bfa>' . $this->escapeLine((string) $attempts) . '</>'));
        }
        if ($exception !== null) {
            $this->line($this->renderPrefixedLine('  <fg=#f97316>│</>', '<fg=#ef4444;options=bold>exception:</> <fg=#fca5a5>' . $this->escapeLine((string) $exception) . '</>'));
        }
        if ($size !== null) {
            $this->line($this->renderPrefixedLine('  <fg=#f97316>│</>', '<fg=#eab308>size:</> <fg=white>' . $this->escapeLine((string) $size) . '</>'));
        }
        $trace = $context['trace'] ?? null;
        if (is_array($trace) && $trace !== []) {
            $this->line($this->renderPrefixedLine('  <fg=#f97316>│</>', '<fg=#6b7280>Trace:</>'));
            foreach (array_slice($trace, 0, 3) as $frame) {
                $this->line($this->renderPrefixedLine('  <fg=#f97316>│</>', '    <fg=#9ca3af>' . $this->escapeLine((string) $frame) . '</>'));
            }
        }
        $this->line('  <fg=#f97316>└─</>');
    }

    /**
     * @param array<string, mixed> $context
     */
    protected function formatHttpCall(array $context): void
    {
        $request = $context['request'] ?? [];
        $response = $context['response'] ?? null;

        $this->line('  <fg=#0d9488>┌─ <options=bold>HTTP Call</></>');

        if (is_array($request) && isset($request['url'])) {
            $method = $request['method'] ?? 'GET';
            $reqUrl = (string) $request['url'];
            $methodColor = in_array(strtoupper((string) $method), ['GET', 'HEAD'], true) ? '#22c55e' : '#3b82f6';
            $this->line($this->renderPrefixedLine('  <fg=#0d9488>│</>', '<fg=' . $methodColor . ';options=bold>' . $this->escapeLine((string) $method) . '</> <fg=#0ea5e9>' . $this->escapeLine($reqUrl) . '</>'));
            if ($this->isUrl($reqUrl)) {
                $this->formatUrl($reqUrl, '#0d9488');
            }
        }

        // Request headers/body
        if (is_array($request)) {
            $requestHeaders = $request['headers'] ?? [];
            if (is_array($requestHeaders) && $requestHeaders !== []) {
                $this->line($this->renderPrefixedLine('  <fg=#0d9488>│</>', '<fg=#eab308;options=bold>Request Headers:</>'));
                $this->formatNestedArray('  <fg=#0d9488>│</>', $this->flattenHeaders($requestHeaders));
            }

            if (array_key_exists('body', $request) && $request['body'] !== null && $request['body'] !== '') {
                $this->line($this->renderPrefixedLine('  <fg=#0d9488>│</>', '<fg=#eab308;options=bold>Request Body:</>'));
                $this->renderBodyContent($request['body'], '  <fg=#0d9488>│</>');
            }
        }

        if (is_array($response)) {
            $status = $response['status_code'] ?? $response['status'] ?? null;
            $duration = $response['duration_ms'] ?? null;
            if ($status !== null) {
                $statusInt = (int) $status;
                $statusColor = $this->statusCodeColor($statusInt);
                $this->line($this->renderPrefixedLine('  <fg=#0d9488>│</>', '<fg=#eab308>Response:</> <fg=' . $statusColor . '>' . $this->escapeLine((string) $status) . '</>'));
            }
            if ($duration !== null) {
                $this->line($this->renderPrefixedLine('  <fg=#0d9488>│</>', '<fg=#eab308>Duration:</> <fg=#22c55e>' . $this->escapeLine((string) $duration) . 'ms</>'));
            }

            $responseHeaders = $response['headers'] ?? [];
            if (is_array($responseHeaders) && $responseHeaders !== []) {
                $this->line($this->renderPrefixedLine('  <fg=#0d9488>│</>', '<fg=#eab308;options=bold>Response Headers:</>'));
                $this->formatNestedArray('  <fg=#0d9488>│</>', $this->flattenHeaders($responseHeaders));
            }

            if (array_key_exists('body', $response) && $response['body'] !== null && $response['body'] !== '') {
                $this->line($this->renderPrefixedLine('  <fg=#0d9488>│</>', '<fg=#eab308;options=bold>Response Body:</>'));
                $this->renderBodyContent($response['body'], '  <fg=#0d9488>│</>');
            }
        }

        $this->line('  <fg=#0d9488>└─</>');
    }

    /**
     * Output request/response body content with fallback formatting.
     * @param mixed $body
     */
    protected function renderBodyContent(mixed $body, string $prefix): void
    {
        if (is_array($body)) {
            $this->outputColorizedJson($this->colorizeJson($body), $prefix);
            return;
        }

        if (is_string($body)) {
            $decoded = json_decode($body, true);
            if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
                $this->outputColorizedJson($this->colorizeJson($decoded), $prefix);
                return;
            }

            $preview = mb_strlen($body) > 500 ? mb_substr($body, 0, 500) . '…' : $body;
            foreach (explode("\n", $preview) as $line) {
                $this->line($this->renderPrefixedLine($prefix, '<fg=#e5e7eb>' . $this->escapeLine($line) . '</>'));
            }
            return;
        }

        $display = is_scalar($body) ? (string) $body : json_encode($body);
        $this->line($this->renderPrefixedLine($prefix, '<fg=#e5e7eb>' . $this->escapeLine((string) $display) . '</>'));
    }

    /**
     * @param array<string, mixed> $context
     */
    protected function formatGenericEvent(string $message, array $context): void
    {
        $this->line('  <fg=#6b7280>┌─</> <fg=#a78bfa>' . $this->escapeLine($message) . '</>');
        if ($context !== []) {
            $this->formatNestedArray('  <fg=#6b7280>│</>', $context);
        }
        $this->line('  <fg=#6b7280>└─</>');
    }

    /**
     * @param array<string, mixed> $data
     */
    protected function formatNestedArray(string $prefix, array $data, int $depth = 0): void
    {
        $indent = str_repeat($this->getJsonIndentUnit(), $depth);
        foreach ($data as $key => $value) {
            $keyPart = '<fg=#eab308>' . $this->escapeLine((string) $key) . '</>: ';
            if (is_array($value) && !$this->isAssoc($value)) {
                $this->line($this->renderPrefixedLine($prefix, $indent . $keyPart . '<fg=#6b7280>[</>'));
                $this->formatNestedArray($prefix, $value, $depth + 1);
                $this->line($this->renderPrefixedLine($prefix, $indent . '  <fg=#6b7280>]</>'));
            } elseif (is_array($value)) {
                $this->line($this->renderPrefixedLine($prefix, $indent . $keyPart));
                $this->formatNestedArray($prefix, $value, $depth + 1);
            } else {
                $display = is_bool($value) ? ($value ? 'true' : 'false') : (string) $value;
                $valColor = is_bool($value) ? '#a78bfa' : 'white';
                $this->line($this->renderPrefixedLine($prefix, $indent . $keyPart . '<fg=' . $valColor . '>' . $this->escapeLine($display) . '</>'));
            }
        }
    }

    /**
     * @param array<mixed> $arr
     */
    protected function isAssoc(array $arr): bool
    {
        if ($arr === []) {
            return false;
        }

        return array_keys($arr) !== range(0, count($arr) - 1);
    }

    /**
     * @param array<string, array<int, string>> $headers
     * @return array<string, string>
     */
    protected function flattenHeaders(array $headers): array
    {
        $out = [];
        foreach ($headers as $name => $values) {
            $out[$name] = is_array($values) ? implode(', ', $values) : (string) $values;
        }

        return $out;
    }
}
