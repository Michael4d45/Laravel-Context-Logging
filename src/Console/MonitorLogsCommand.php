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
                            {--threshold=50 : Skip batch if new lines exceed this (0 = no limit)}';

    /**
     * The console command description.
     */
    protected $description = 'Tail and pretty-print JSON-formatted context logs';

    protected int $skipThreshold = 50;

    public function handle(): int
    {
        $file = $this->argument('file') ?? storage_path('logs/laravel.log');
        $this->skipThreshold = (int) $this->option('threshold');

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
        $indent = str_repeat('  ', $depth);
        $isList = !$this->isAssoc($data);

        if ($data === []) {
            $lines[] = $indent . '<fg=gray>[]</>';

            return $lines;
        }

        $open = $isList ? '<fg=gray>[</>' : '<fg=gray>{</>';
        $close = $isList ? '<fg=gray>]</>' : '<fg=gray>}</>';
        $lines[] = $indent . $open;

        $idx = 0;
        $last = array_key_last($data);
        foreach ($data as $key => $value) {
            $isLast = ($key === $last);
            $comma = $isLast ? '' : '<fg=gray>,</>';
            $innerIndent = str_repeat('  ', $depth + 1);

            if ($isList) {
                $valuePart = $this->colorizeJsonValue($value, $depth + 1);
                if (is_array($valuePart)) {
                    foreach ($valuePart as $nl) {
                        $lines[] = $innerIndent . $nl;
                    }
                    $lines[array_key_last($lines)] .= $comma;
                } else {
                    $lines[] = $innerIndent . $valuePart . $comma;
                }
            } else {
                $keyEsc = $this->escapeLine($this->escapeJsonString((string) $key));
                $keyPart = '<fg=#e6db74>"' . $keyEsc . '"</><fg=gray>: </>';
                $valuePart = $this->colorizeJsonValue($value, $depth + 1);
                if (is_array($valuePart)) {
                    $lines[] = $innerIndent . $keyPart . ltrim($valuePart[0]);
                    foreach (array_slice($valuePart, 1) as $nl) {
                        $lines[] = $innerIndent . $nl;
                    }
                    $lines[array_key_last($lines)] .= $comma;
                } else {
                    $lines[] = $innerIndent . $keyPart . $valuePart . $comma;
                }
            }
            $idx++;
        }

        $lines[] = $indent . $close;

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
            $this->line("  <fg={$barColor}>│</>   " . $this->escapeLine($url));

            return;
        }

        $parsed = parse_url($url);
        $scheme = $parsed['scheme'] ?? 'https';
        $host = $parsed['host'] ?? '';
        $port = isset($parsed['port']) ? ':' . $parsed['port'] : '';
        $base = $scheme . '://' . $host . $port;
        $path = $parsed['path'] ?? '/';
        $query = $parsed['query'] ?? null;

        $this->line("  <fg={$barColor}>│</>   <fg=green>Base:</> " . $this->escapeLine($base));
        $this->line("  <fg={$barColor}>│</>   <fg=green>Path:</> " . $this->escapeLine($path));

        if ($query !== null && $query !== '') {
            $this->line("  <fg={$barColor}>│</>   <fg=green>Query Params:</>");
            foreach (explode('&', $query) as $param) {
                $eq = strpos($param, '=');
                if ($eq !== false) {
                    $key = substr($param, 0, $eq);
                    $value = substr($param, $eq + 1);
                    $value = rawurldecode($value);
                    $this->line("  <fg={$barColor}>│</>     <fg=cyan>" . $this->escapeLine($key) . "</> = " . $this->escapeLine($value));
                } else {
                    $this->line("  <fg={$barColor}>│</>     " . $this->escapeLine($param));
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
        $this->line("<bg={$levelColor};fg=white;options=bold> {$level} </> <fg=gray>{$timestamp}</> {$message}");

        $ctx = $context['context'] ?? null;
        $events = $context['events'] ?? null;

        if (is_array($ctx) && $ctx !== []) {
            $this->formatContextBlock($ctx);
        }

        if (is_array($events) && $events !== []) {
            $this->processTimelineEvents($events, $ctx ?? []);
        }

        $this->line('<fg=gray>──────────────────────────────────────────────────────────────────────────────</>');
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
        $this->line('  <fg=gray>┌─ Context</>');

        $keys = ['request_id', 'run_id', 'method', 'path', 'full_url', 'ip', 'duration_ms', 'status', 'command'];
        foreach ($keys as $key) {
            if (!array_key_exists($key, $ctx)) {
                continue;
            }
            $value = $ctx[$key];
            if ($key === 'full_url' && is_scalar($value) && $this->isUrl((string) $value)) {
                $this->line('  <fg=gray>│</>   <options=bold>full_url</>:');
                $this->formatUrl((string) $value, 'gray');
                continue;
            }
            if (is_array($value)) {
                $this->line('  <fg=gray>│</>   <options=bold>' . $key . '</>:');
                $this->outputColorizedJson($this->colorizeJson($value), '  <fg=gray>│</>   ');
                continue;
            }
            $display = is_scalar($value) ? (string) $value : json_encode($value);
            $this->line('  <fg=gray>│</>   <options=bold>' . $key . '</>: ' . $this->escapeLine($display));
        }

        $rest = array_diff_key($ctx, array_flip($keys));
        if ($rest !== []) {
            foreach ($rest as $key => $value) {
                if (is_array($value)) {
                    $this->line('  <fg=gray>│</>   <options=bold>' . $key . '</>:');
                    $this->outputColorizedJson($this->colorizeJson($value), '  <fg=gray>│</>   ');
                    continue;
                }
                $display = is_scalar($value) ? (string) $value : json_encode($value);
                $this->line('  <fg=gray>│</>   <options=bold>' . $key . '</>: ' . $this->escapeLine($display));
            }
        }

        $this->line('  <fg=gray>└─</>');
    }

    /**
     * @param array<int, string> $lines Lines that may contain Symfony Console tags
     */
    protected function outputColorizedJson(array $lines, string $prefix): void
    {
        foreach ($lines as $line) {
            $this->line($prefix . $line);
        }
    }

    /**
     * @param array<int, array{message?: string, context?: array<string, mixed>, level?: string, timestamp?: float}> $events
     * @param array<string, mixed> $mainContext
     */
    protected function processTimelineEvents(array $events, array $mainContext = []): void
    {
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

        $this->line('  <fg=cyan>┌─ [SQL]</> ' . $this->escapeLine((string) $time));

        if (class_exists(\Doctrine\SqlFormatter\SqlFormatter::class) && class_exists(\Doctrine\SqlFormatter\CliHighlighter::class)) {
            $formatter = new \Doctrine\SqlFormatter\SqlFormatter(new \Doctrine\SqlFormatter\CliHighlighter());
            $formatted = $formatter->format($sql);
            foreach (explode("\n", $formatted) as $formattedLine) {
                $this->line('  <fg=cyan>│</>   ' . $formattedLine);
            }
        } else {
            foreach (explode("\n", $sql) as $sqlLine) {
                $this->line('  <fg=cyan>│</>   ' . $this->escapeLine($sqlLine));
            }
        }

        $trace = $context['trace'] ?? null;
        if (is_array($trace) && $trace !== []) {
            $this->line('  <fg=cyan>│</>   <fg=gray>Trace:</>');
            foreach (array_slice($trace, 0, 5) as $frame) {
                $this->line('  <fg=cyan>│</>     ' . $this->escapeLine((string) $frame));
            }
        }

        $this->line('  <fg=cyan>└─</>');
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

        $this->line('  <fg=green>┌─ Incoming Request</>');
        $this->line('  <fg=green>│</>   <options=bold>' . $this->escapeLine((string) $method) . '</> ' . $this->escapeLine((string) $url));
        if ($url !== '' && $this->isUrl($url)) {
            $this->formatUrl($url, 'green');
        }
        if ($ip !== null && $ip !== '') {
            $this->line('  <fg=green>│</>   IP: ' . $this->escapeLine((string) $ip));
        }
        if ($userAgent !== null && $userAgent !== '') {
            $this->line('  <fg=green>│</>   User-Agent: ' . $this->escapeLine((string) $userAgent));
        }

        if (is_array($body) && $body !== []) {
            $this->line('  <fg=green>│</>   <options=bold>Body:</>');
            $colored = $this->colorizeJson($body);
            $this->outputColorizedJson($colored, '  <fg=green>│</>   ');
        } elseif (!is_array($body) && (string) $body !== '') {
            $bodyStr = (string) $body;
            $decoded = json_decode($bodyStr, true);
            if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
                $this->line('  <fg=green>│</>   <options=bold>Body:</>');
                $this->outputColorizedJson($this->colorizeJson($decoded), '  <fg=green>│</>   ');
            } else {
                $this->line('  <fg=green>│</>   Body: ' . $this->escapeLine($bodyStr));
            }
        }

        if (is_array($queryParams) && $queryParams !== []) {
            $this->line('  <fg=green>│</>   <options=bold>Query:</>');
            $this->formatNestedArray('  <fg=green>│</>   ', $queryParams);
        }

        if (is_array($headers) && $headers !== []) {
            $this->line('  <fg=green>│</>   <options=bold>Headers:</>');
            $this->formatNestedArray('  <fg=green>│</>   ', $this->flattenHeaders($headers));
        }

        $this->line('  <fg=green>└─</>');
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

        $this->line('  <fg=magenta>┌─ Outgoing Response</>');
        if ($status !== null) {
            $this->line('  <fg=magenta>│</>   Status: ' . $this->escapeLine((string) $status));
        }
        if ($redirectTarget !== null && $redirectTarget !== '') {
            $this->line('  <fg=magenta>│</>   Redirect: ' . $this->escapeLine((string) $redirectTarget));
        }
        if ($contentType !== null && $contentType !== '') {
            $this->line('  <fg=magenta>│</>   Content-Type: ' . $this->escapeLine((string) $contentType));
        }
        if ($contentLength !== null) {
            $this->line('  <fg=magenta>│</>   Content-Length: ' . $this->escapeLine((string) $contentLength));
        }

        if (is_array($body) && $body !== []) {
            $this->line('  <fg=magenta>│</>   <options=bold>Body:</>');
            $colored = $this->colorizeJson($body);
            $this->outputColorizedJson($colored, '  <fg=magenta>│</>   ');
        } elseif (is_string($body) && $body !== '') {
            $decoded = json_decode($body, true);
            if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
                $this->line('  <fg=magenta>│</>   <options=bold>Body:</>');
                $colored = $this->colorizeJson($decoded);
                $this->outputColorizedJson($colored, '  <fg=magenta>│</>   ');
            } else {
                $preview = strlen($body) > 500 ? substr($body, 0, 500) . '…' : $body;
                foreach (explode("\n", $preview) as $bodyLine) {
                    $this->line('  <fg=magenta>│</>   ' . $this->escapeLine($bodyLine));
                }
            }
        }

        if (is_array($headers) && $headers !== []) {
            $this->line('  <fg=magenta>│</>   <options=bold>Headers:</>');
            $this->formatNestedArray('  <fg=magenta>│</>   ', $this->flattenHeaders($headers));
        }

        $this->line('  <fg=magenta>└─</>');
    }

    /**
     * @param array<string, mixed> $context
     */
    /**
     * @param array<string, mixed> $context User event context (keys from config context-logging.log.user_attributes + timestamp)
     */
    protected function formatUser(array $context): void
    {
        $this->line('  <fg=yellow>┌─ User</>');
        foreach ($context as $key => $value) {
            if ($value === null) {
                continue;
            }
            $display = is_bool($value) ? ($value ? 'true' : 'false') : $this->escapeLine((string) $value);
            $this->line('  <fg=yellow>│</>   ' . $this->escapeLine((string) $key) . ': ' . $display);
        }
        $this->line('  <fg=yellow>└─</>');
    }

    /**
     * @param array<string, mixed> $context
     */
    protected function formatCacheEvent(array $context): void
    {
        $event = $context['event'] ?? 'cache';
        $key = $context['key'] ?? '';
        $expiration = $context['expiration'] ?? null;

        $this->line('  <fg=blue>┌─ Cache</> ' . $this->escapeLine((string) $event) . ' key: ' . $this->escapeLine((string) $key));
        if ($expiration !== null) {
            $this->line('  <fg=blue>│</>   expiration: ' . $this->escapeLine((string) $expiration) . 's');
        }
        $trace = $context['trace'] ?? null;
        if (is_array($trace) && $trace !== []) {
            foreach (array_slice($trace, 0, 3) as $frame) {
                $this->line('  <fg=blue>│</>   ' . $this->escapeLine((string) $frame));
            }
        }
        $this->line('  <fg=blue>└─</>');
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

        $this->line('  <fg=#ff9800>┌─ Queue</> ' . $this->escapeLine((string) $event));
        $this->line('  <fg=#ff9800>│</>   job: ' . $this->escapeLine((string) $job));
        if ($queue !== null) {
            $this->line('  <fg=#ff9800>│</>   queue: ' . $this->escapeLine((string) $queue));
        }
        if ($connection !== null) {
            $this->line('  <fg=#ff9800>│</>   connection: ' . $this->escapeLine((string) $connection));
        }
        if ($attempts !== null) {
            $this->line('  <fg=#ff9800>│</>   attempts: ' . $this->escapeLine((string) $attempts));
        }
        if ($exception !== null) {
            $this->line('  <fg=#ff9800>│</>   <fg=red>exception: ' . $this->escapeLine((string) $exception) . '</>');
        }
        if ($size !== null) {
            $this->line('  <fg=#ff9800>│</>   size: ' . $this->escapeLine((string) $size));
        }
        $trace = $context['trace'] ?? null;
        if (is_array($trace) && $trace !== []) {
            foreach (array_slice($trace, 0, 3) as $frame) {
                $this->line('  <fg=#ff9800>│</>   ' . $this->escapeLine((string) $frame));
            }
        }
        $this->line('  <fg=#ff9800>└─</>');
    }

    /**
     * @param array<string, mixed> $context
     */
    protected function formatHttpCall(array $context): void
    {
        $request = $context['request'] ?? [];
        $response = $context['response'] ?? null;

        $this->line('  <fg=cyan>┌─ HTTP Call</>');
        if (is_array($request) && isset($request['url'])) {
            $method = $request['method'] ?? 'GET';
            $reqUrl = (string) $request['url'];
            $this->line('  <fg=cyan>│</>   ' . $this->escapeLine((string) $method) . ' ' . $this->escapeLine($reqUrl));
            if ($this->isUrl($reqUrl)) {
                $this->formatUrl($reqUrl, 'cyan');
            }
        }
        if (is_array($response)) {
            $status = $response['status_code'] ?? $response['status'] ?? null;
            $duration = $response['duration_ms'] ?? null;
            if ($status !== null) {
                $this->line('  <fg=cyan>│</>   Response: ' . $this->escapeLine((string) $status));
            }
            if ($duration !== null) {
                $this->line('  <fg=cyan>│</>   Duration: ' . $this->escapeLine((string) $duration) . 'ms');
            }
        }
        $this->line('  <fg=cyan>└─</>');
    }

    /**
     * @param array<string, mixed> $context
     */
    protected function formatGenericEvent(string $message, array $context): void
    {
        $this->line('  <fg=gray>┌─</> ' . $this->escapeLine($message));
        if ($context !== []) {
            $this->formatNestedArray('  <fg=gray>│</>   ', $context);
        }
        $this->line('  <fg=gray>└─</>');
    }

    /**
     * @param array<string, mixed> $data
     */
    protected function formatNestedArray(string $prefix, array $data, int $depth = 0): void
    {
        $indent = str_repeat('  ', $depth);
        foreach ($data as $key => $value) {
            if (is_array($value) && !$this->isAssoc($value)) {
                $this->line($prefix . $indent . $this->escapeLine((string) $key) . ': [');
                $this->formatNestedArray($prefix, $value, $depth + 1);
                $this->line($prefix . $indent . ']');
            } elseif (is_array($value)) {
                $this->line($prefix . $indent . $this->escapeLine((string) $key) . ':');
                $this->formatNestedArray($prefix, $value, $depth + 1);
            } else {
                $display = is_bool($value) ? ($value ? 'true' : 'false') : (string) $value;
                $this->line($prefix . $indent . $this->escapeLine((string) $key) . ': ' . $this->escapeLine($display));
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
