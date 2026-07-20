<?php

declare(strict_types=1);

namespace Michael4d45\ContextLogging\Sentry;

use Michael4d45\ContextLogging\ContextStore;
use Michael4d45\ContextLogging\Support\TraceHelper;

/**
 * Captures prepared Sentry SDK events into the ContextStore via before_send.
 *
 * Soft-depends on sentry/sentry: no-ops when the SDK is absent. Does not log
 * through Log::* (avoids ContextualLogger recursion).
 */
final class SentryBridge
{
    /** @var array<string, true> */
    private array $seenFingerprints = [];

    public function __construct(
        private readonly ContextStore $contextStore,
    ) {}

    public function register(): void
    {
        if (! (bool) config('context-logging.sentry.enabled', false)) {
            return;
        }

        if (! class_exists(\Sentry\Event::class) || ! class_exists(\Sentry\SentrySdk::class)) {
            return;
        }

        $install = function (): void {
            $this->installCallbacks();
        };

        if (function_exists('app') && app()->isBooted()) {
            $install();

            return;
        }

        if (function_exists('app')) {
            app()->booted($install);
        }
    }

    private function installCallbacks(): void
    {
        $client = null;

        try {
            if (function_exists('app') && app()->bound(\Sentry\State\HubInterface::class)) {
                $client = app(\Sentry\State\HubInterface::class)->getClient();
            }
            $client ??= \Sentry\SentrySdk::getCurrentHub()->getClient();
        } catch (\Throwable) {
            return;
        }

        if ($client === null) {
            return;
        }

        $options = $client->getOptions();
        $drop = (bool) config('context-logging.sentry.drop', true);
        $captureTransactions = (bool) config('context-logging.sentry.capture_transactions', false);

        $previousSend = $options->getBeforeSendCallback();
        $options->setBeforeSendCallback(function ($event, $hint = null) use ($previousSend, $drop) {
            $this->capture($event, $hint);

            if (is_callable($previousSend)) {
                $event = $previousSend($event, $hint);
            }

            if ($event === null) {
                return null;
            }

            return $drop ? null : $event;
        });

        $previousTxn = $options->getBeforeSendTransactionCallback();
        $options->setBeforeSendTransactionCallback(function ($event, $hint = null) use ($previousTxn, $drop, $captureTransactions) {
            if ($captureTransactions) {
                $this->capture($event, $hint);
            }

            if (is_callable($previousTxn)) {
                $event = $previousTxn($event, $hint);
            }

            if ($event === null) {
                return null;
            }

            return $drop ? null : $event;
        });
    }

    /**
     * @param  object  $event  \Sentry\Event
     * @param  object|null  $hint  \Sentry\EventHint|null
     */
    public function capture(object $event, ?object $hint = null): void
    {
        $payload = $this->normalize($event, $hint);
        if ($payload === null) {
            return;
        }

        $fingerprint = (string) ($payload['fingerprint'] ?? '');
        unset($payload['fingerprint']);

        // Dedupe only by Sentry event id so Horizon workers can re-see the same
        // exception class on later jobs. Cap memory for long-lived processes.
        if ($fingerprint !== '' && str_starts_with($fingerprint, 'id:')) {
            if (isset($this->seenFingerprints[$fingerprint])) {
                return;
            }
            $this->seenFingerprints[$fingerprint] = true;
            if (count($this->seenFingerprints) > 500) {
                $this->seenFingerprints = array_slice($this->seenFingerprints, -250, preserve_keys: true);
            }
        }

        $level = (string) ($payload['level'] ?? 'error');
        $message = (string) ($payload['exception'] ?? $payload['message'] ?? 'Sentry event');

        $this->contextStore->addEvent($level, $message, $payload);
    }

    /**
     * Normalize a Sentry event into a ContextStore event context.
     *
     * @param  object  $event  \Sentry\Event
     * @param  object|null  $hint  \Sentry\EventHint|null
     * @return array<string, mixed>|null
     */
    public function normalize(object $event, ?object $hint = null): ?array
    {
        if (! $event instanceof \Sentry\Event) {
            return null;
        }

        $type = (string) $event->getType();
        if ($type === 'transaction' && ! (bool) config('context-logging.sentry.capture_transactions', false)) {
            return null;
        }
        if (in_array($type, ['session', 'client_report', 'check_in'], true)) {
            return null;
        }

        $exception = null;
        if ($hint instanceof \Sentry\EventHint && $hint->exception instanceof \Throwable) {
            $exception = $hint->exception;
        }

        $exceptions = $event->getExceptions();
        $primary = $exceptions[array_key_last($exceptions)] ?? null;

        $exceptionClass = $exception !== null
            ? $exception::class
            : ($primary?->getType() ?: null);
        $exceptionMessage = $exception !== null
            ? $exception->getMessage()
            : ($primary?->getValue() ?: null);

        $message = $event->getMessage();
        if ($exceptionClass === null && ($message === null || $message === '')) {
            return null;
        }

        $stacktrace = $primary?->getStacktrace() ?? $event->getStacktrace();
        if ($stacktrace === null && $hint instanceof \Sentry\EventHint) {
            $stacktrace = $hint->stacktrace;
        }

        $frames = $this->collapseFrames($stacktrace);
        $file = null;
        $line = null;
        if ($exception !== null) {
            $file = $exception->getFile() ?: null;
            $line = $exception->getLine() ?: null;
        }
        if (($file === null || $file === '') && $frames !== []) {
            [$file, $line] = $this->splitPathLine($frames[0]);
        }

        $severity = (string) ($event->getLevel() ?? 'error');
        $level = match ($severity) {
            'fatal', 'critical' => 'critical',
            'error' => 'error',
            'warning' => 'warning',
            'info', 'log' => 'info',
            'debug' => 'debug',
            default => 'error',
        };

        $eventId = (string) $event->getId();
        $fingerprint = $eventId !== ''
            ? 'id:'.$eventId
            : 'exc:'.md5(($exceptionClass ?? '').'|'.($exceptionMessage ?? $message ?? '').'|'.($file ?? '').'|'.($line ?? ''));

        $maxBreadcrumbs = max(0, (int) config('context-logging.sentry.max_breadcrumbs', 20));
        $breadcrumbs = [];
        foreach (array_slice($event->getBreadcrumbs(), -$maxBreadcrumbs) as $crumb) {
            $breadcrumbs[] = [
                'type' => method_exists($crumb, 'getType') ? $crumb->getType() : null,
                'category' => method_exists($crumb, 'getCategory') ? $crumb->getCategory() : null,
                'message' => method_exists($crumb, 'getMessage') ? $crumb->getMessage() : null,
                'level' => method_exists($crumb, 'getLevel') ? (string) $crumb->getLevel() : null,
                'timestamp' => method_exists($crumb, 'getTimestamp') ? $crumb->getTimestamp() : null,
            ];
        }

        $user = $event->getUser();
        $userPayload = null;
        if ($user !== null) {
            $userPayload = array_filter([
                'id' => $user->getId(),
                'email' => $user->getEmail(),
                'username' => $user->getUsername(),
                'ip_address' => $user->getIpAddress(),
            ], static fn ($v) => $v !== null && $v !== '');
            if ($userPayload === []) {
                $userPayload = null;
            }
        }

        $relativeFile = $this->relativePath($file);

        return array_filter([
            'source' => 'sentry',
            'fingerprint' => $fingerprint,
            'sentry_event_id' => $eventId !== '' ? $eventId : null,
            'sentry_type' => $type !== '' ? $type : null,
            'exception' => $exceptionClass,
            'message' => $exceptionMessage ?? $message,
            'file' => $relativeFile,
            'line' => $line,
            'level' => $level,
            'transaction' => $event->getTransaction(),
            'tags' => $event->getTags() !== [] ? $event->getTags() : null,
            'extra' => $event->getExtra() !== [] ? $event->getExtra() : null,
            'contexts' => $this->trimContexts($event->getContexts()),
            'user' => $userPayload,
            'breadcrumbs' => $breadcrumbs !== [] ? $breadcrumbs : null,
            'trace' => $frames !== [] ? $frames : null,
        ], static fn ($v) => $v !== null && $v !== []);
    }

    /**
     * @param  object|null  $stacktrace  \Sentry\Stacktrace|null
     * @return list<string>
     */
    private function collapseFrames(?object $stacktrace): array
    {
        if ($stacktrace === null || ! method_exists($stacktrace, 'getFrames')) {
            return [];
        }

        $max = max(1, (int) config('context-logging.sentry.max_frames', 40));
        $lines = [];

        /** @var list<object> $frames */
        $frames = array_reverse($stacktrace->getFrames());
        foreach ($frames as $frame) {
            if (! method_exists($frame, 'getFile') || ! method_exists($frame, 'getLine')) {
                continue;
            }

            $file = str_replace('\\', '/', (string) $frame->getFile());
            $line = (int) $frame->getLine();
            if ($file === '' || $file === '[internal]' || str_starts_with($file, '/internal/')) {
                continue;
            }

            $absolute = method_exists($frame, 'getAbsoluteFilePath')
                ? str_replace('\\', '/', (string) ($frame->getAbsoluteFilePath() ?? $file))
                : $file;

            // Skip configured ignore paths (default: app vendor; sidecars can add more).
            if (TraceHelper::shouldIgnoreFile($absolute) || TraceHelper::shouldIgnoreFile($file)) {
                continue;
            }

            $relative = $this->relativePath($absolute) ?? $this->relativePath($file) ?? $file;
            if (
                str_contains($relative, 'ContextLogging')
                || str_ends_with($relative, 'artisan')
                || str_contains($relative, 'public/index.php')
            ) {
                continue;
            }

            $lines[] = $line > 0 ? "{$relative}:{$line}" : $relative;
            if (count($lines) >= $max) {
                break;
            }
        }

        return $lines;
    }

    /**
     * @param  array<string, mixed>  $contexts
     * @return array<string, mixed>|null
     */
    private function trimContexts(array $contexts): ?array
    {
        if ($contexts === []) {
            return null;
        }

        // Keep useful debugging contexts; drop bulky trace payloads.
        unset($contexts['trace']);

        return $contexts !== [] ? $contexts : null;
    }

    private function relativePath(?string $path): ?string
    {
        if ($path === null || $path === '') {
            return null;
        }

        $path = str_replace('\\', '/', $path);
        $basePath = str_replace('\\', '/', (string) (function_exists('base_path') ? base_path() : ''));
        if ($basePath !== '' && str_starts_with($path, $basePath.'/')) {
            return substr($path, strlen($basePath) + 1);
        }

        return $path;
    }

    /**
     * @return array{0: string|null, 1: int|null}
     */
    private function splitPathLine(string $frame): array
    {
        if (preg_match('/\A(.+):(\d+)\z/', $frame, $matches)) {
            return [$matches[1], (int) $matches[2]];
        }

        return [$frame, null];
    }
}
