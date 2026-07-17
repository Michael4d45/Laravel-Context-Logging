<?php

namespace Michael4d45\ContextLogging;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\RequestException;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Promise\Create as PromiseCreate;
use Illuminate\Support\Facades\Http;
use Michael4d45\ContextLogging\Guzzle\ClientPatch;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use Throwable;

/**
 * Handles outbound HTTP request/response context capture.
 *
 * Shared Guzzle middleware powers:
 * - Laravel Http::globalMiddleware() (when guzzle_patch is off)
 * - Transparent Guzzle Client constructor patch (sidecar / zero app changes)
 * - Explicit instrument() / pushOnto() helpers
 */
class HttpClientInstrumentation
{
    public const MIDDLEWARE_NAME = 'context-logging';

    protected bool $registered = false;

    public function __construct(
        protected ContextStore $contextStore,
    ) {}

    /**
     * Enable outbound HTTP recording once.
     *
     * When guzzle_patch is enabled and the Client autoload patch is present, Laravel Http
     * is covered by the patched Guzzle Client, so facade middleware is skipped.
     * If the patch is missing, falls back to Http::globalMiddleware so capture still works.
     */
    public function register(): void
    {
        if ($this->registered) {
            return;
        }

        $this->registered = true;

        if ($this->guzzlePatchEnabled()) {
            if (ClientPatch::isClientPatched()) {
                return;
            }

            ClientPatch::warnIfPatchMissing();
        }

        Http::globalMiddleware($this->guzzleMiddleware());
    }

    /**
     * Whether the transparent Guzzle Client patch should push middleware.
     */
    public function guzzlePatchEnabled(): bool
    {
        return (bool) config('context-logging.http.enabled', false)
            && (bool) config('context-logging.http.guzzle_patch', false);
    }

    /**
     * Guzzle middleware compatible with HandlerStack and Http::globalMiddleware().
     */
    public function guzzleMiddleware(): callable
    {
        $contextStore = $this->contextStore;

        return function (callable $handler) use ($contextStore) {
            return function ($request, array $options) use ($handler, $contextStore) {
                if (!$request instanceof RequestInterface) {
                    return $handler($request, $options);
                }

                $requestStart = microtime(true);
                $url = (string) $request->getUri();
                $urlParts = $this->extractUrlParts($url);

                $requestData = [
                    'method' => $request->getMethod(),
                    'url' => $url,
                    'path' => $urlParts['path'],
                    'query_params' => $this->maskSensitiveQueryParams($urlParts['query_params']),
                    'timestamp' => $requestStart,
                ];

                $service = $this->resolveServiceLabel($url);
                if ($service !== null) {
                    $requestData['service'] = $service;
                }

                if ((bool) config('context-logging.http.capture_headers', false)) {
                    $requestData['headers'] = $this->normalizeHeaders($request->getHeaders());
                }

                if ((bool) config('context-logging.http.capture_body', false)) {
                    $body = $this->readMessageBody($request);
                    if ($body !== null && $body !== '') {
                        $requestData['body'] = $this->decodeAndMaskJsonBody(
                            $request->getHeader('Content-Type'),
                            $body
                        );
                    }
                }

                $httpCallId = $contextStore->beginHttpCall($requestData);
                $options['context']['context_logging_http_call_id'] = $httpCallId;

                $promise = $handler($request, $options);

                return $promise->then(
                    function ($response) use ($httpCallId, $contextStore) {
                        $responseTs = microtime(true);

                        if (!$response instanceof ResponseInterface) {
                            $contextStore->completeHttpCall($httpCallId, [
                                'status' => 0,
                                'timestamp' => $responseTs,
                            ]);

                            return $response;
                        }

                        $responseData = [
                            'status' => $response->getStatusCode(),
                            'timestamp' => $responseTs,
                        ];

                        if ((bool) config('context-logging.http.capture_headers', false)) {
                            $responseData['headers'] = $this->normalizeHeaders($response->getHeaders());
                        }

                        if ((bool) config('context-logging.http.capture_body', false)) {
                            $body = $this->readMessageBody($response);
                            if ($body !== null && $body !== '') {
                                $responseData['body'] = $this->decodeAndMaskJsonBody(
                                    $response->getHeader('Content-Type'),
                                    $body
                                );
                            }
                        }

                        $contextStore->completeHttpCall($httpCallId, $responseData);

                        return $response;
                    },
                    function ($reason) use ($httpCallId, $contextStore) {
                        $contextStore->completeHttpCall(
                            $httpCallId,
                            $this->buildFailureResponseData($reason)
                        );

                        return PromiseCreate::rejectionFor($reason);
                    }
                );
            };
        };
    }

    /**
     * Push instrumentation onto an existing handler stack (e.g. Everflow).
     */
    public function pushOnto(HandlerStack $stack): void
    {
        $stack->remove(self::MIDDLEWARE_NAME);
        $stack->push($this->guzzleMiddleware(), self::MIDDLEWARE_NAME);
    }

    /**
     * Instrument a Client, HandlerStack, or Client config array.
     *
     * @param  Client|HandlerStack|array<string, mixed>  $target
     * @return Client|HandlerStack|array<string, mixed>
     */
    public function instrument(Client|HandlerStack|array $target): Client|HandlerStack|array
    {
        if ($target instanceof Client) {
            $handler = $target->getConfig('handler');
            if ($handler instanceof HandlerStack) {
                $this->pushOnto($handler);
            }

            return $target;
        }

        if ($target instanceof HandlerStack) {
            $this->pushOnto($target);

            return $target;
        }

        return $this->applyToClientConfig($target);
    }

    /**
     * Ensure a Guzzle Client config array has instrumentation on its handler.
     *
     * @param  array<string, mixed>  $config
     * @return array<string, mixed>
     */
    public function applyToClientConfig(array $config): array
    {
        if (! $this->shouldInstrumentClientConfig()) {
            return $config;
        }

        $handler = $config['handler'] ?? null;

        if ($handler instanceof HandlerStack) {
            $this->pushOnto($handler);
            $config['handler'] = $handler;

            return $config;
        }

        if (is_callable($handler)) {
            $stack = new HandlerStack($handler);
            $this->pushOnto($stack);
            $config['handler'] = $stack;

            return $config;
        }

        // Let Guzzle build the default stack (preserves transport_sharing, etc.).
        // ClientPatch::afterConstruct will push middleware afterward.
        if (array_key_exists('transport_sharing', $config)) {
            return $config;
        }

        $stack = HandlerStack::create();
        $this->pushOnto($stack);
        $config['handler'] = $stack;

        return $config;
    }

    /**
     * Create a new instrumented Guzzle client.
     *
     * @param  array<string, mixed>  $config
     */
    public function createClient(array $config = []): Client
    {
        return new Client($this->applyToClientConfig($config));
    }

    /**
     * @param  mixed  $reason
     * @return array<string, mixed>
     */
    protected function buildFailureResponseData(mixed $reason): array
    {
        $responseData = [
            'status' => 0,
            'timestamp' => microtime(true),
        ];

        if ($reason instanceof Throwable) {
            $responseData['error'] = $reason->getMessage();
            $responseData['error_class'] = $reason::class;
        } else {
            $responseData['error'] = is_scalar($reason) ? (string) $reason : 'HTTP request failed';
            $responseData['error_class'] = get_debug_type($reason);
        }

        if ($reason instanceof RequestException && $reason->hasResponse()) {
            $response = $reason->getResponse();
            $responseData['status'] = $response->getStatusCode();

            if ((bool) config('context-logging.http.capture_headers', false)) {
                $responseData['headers'] = $this->normalizeHeaders($response->getHeaders());
            }

            if ((bool) config('context-logging.http.capture_body', false)) {
                $body = $this->readMessageBody($response);
                if ($body !== null && $body !== '') {
                    $responseData['body'] = $this->decodeAndMaskJsonBody(
                        $response->getHeader('Content-Type'),
                        $body
                    );
                }
            }
        }

        return $responseData;
    }

    protected function shouldInstrumentClientConfig(): bool
    {
        if (! (bool) config('context-logging.http.enabled', false)) {
            return false;
        }

        // Explicit instrument()/createClient() always attach when HTTP logging is on.
        // Constructor patch uses the same helper but is gated by guzzle_patch in ClientPatch.
        return true;
    }

    protected function resolveServiceLabel(string $url): ?string
    {
        $host = parse_url($url, PHP_URL_HOST);

        return is_string($host) && $host !== '' ? strtolower($host) : null;
    }

    /**
     * @param  array<string, array<int, string>>  $headers
     * @return array<string, string>
     */
    protected function normalizeHeaders(array $headers): array
    {
        $normalized = [];
        $redacted = array_map('strtolower', (array) config('context-logging.http.redact_headers', []));

        foreach ($headers as $name => $values) {
            $key = strtolower((string) $name);

            if (in_array($key, $redacted, true)) {
                $normalized[$key] = $this->redactionMask();
                continue;
            }

            $normalized[$key] = implode(', ', $values);
        }

        return $normalized;
    }

    /**
     * @param  \Psr\Http\Message\MessageInterface  $message
     */
    protected function readMessageBody(object $message): ?string
    {
        if (! method_exists($message, 'getBody')) {
            return null;
        }

        $stream = $message->getBody();
        if (! is_object($stream) || ! method_exists($stream, '__toString')) {
            return null;
        }

        $position = null;
        if (method_exists($stream, 'tell')) {
            try {
                $position = $stream->tell();
            } catch (Throwable) {
                $position = null;
            }
        }

        $body = (string) $stream;

        if ($position !== null && method_exists($stream, 'seek') && method_exists($stream, 'isSeekable') && $stream->isSeekable()) {
            try {
                $stream->seek($position);
            } catch (Throwable) {
                // Leave stream where it is if rewind fails.
            }
        } elseif (method_exists($stream, 'rewind') && method_exists($stream, 'isSeekable') && $stream->isSeekable()) {
            try {
                $stream->rewind();
            } catch (Throwable) {
                // Ignore.
            }
        }

        return $body;
    }

    /**
     * Decode JSON response body when applicable, otherwise return raw body.
     *
     * @param  array<int, string>  $contentTypeHeaders
     */
    protected function decodeAndMaskJsonBody(array $contentTypeHeaders, string $body): mixed
    {
        if (! $this->isJsonByContentType($contentTypeHeaders)) {
            return $body;
        }

        $decoded = json_decode($body, true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            return $body;
        }

        return $this->maskSensitiveBodyData($decoded);
    }

    /**
     * @param  array<int, string>  $contentTypeHeaders
     */
    protected function isJsonByContentType(array $contentTypeHeaders): bool
    {
        foreach ($contentTypeHeaders as $contentType) {
            $normalized = strtolower($contentType);

            if (str_contains($normalized, 'application/json') || str_contains($normalized, '+json')) {
                return true;
            }
        }

        return false;
    }

    protected function maskSensitiveBodyData(mixed $payload): mixed
    {
        if (! is_array($payload)) {
            return $payload;
        }

        $masked = [];

        foreach ($payload as $key => $value) {
            if (is_string($key) && $this->isSensitiveBodyField($key)) {
                $masked[$key] = $this->redactionMask();
                continue;
            }

            $masked[$key] = $this->maskSensitiveBodyData($value);
        }

        return $masked;
    }

    protected function isSensitiveBodyField(string $field): bool
    {
        $redactedFields = array_map('strtolower', (array) config('context-logging.http.redact_body_fields', []));

        return in_array(strtolower($field), $redactedFields, true);
    }

    /**
     * @return array{path: string, query_params: array<string, mixed>}
     */
    protected function extractUrlParts(string $url): array
    {
        $path = parse_url($url, PHP_URL_PATH);
        $query = parse_url($url, PHP_URL_QUERY);

        $queryParams = [];

        if (is_string($query) && $query !== '') {
            parse_str($query, $queryParams);
        }

        return [
            'path' => is_string($path) && $path !== '' ? $path : '/',
            'query_params' => $queryParams,
        ];
    }

    protected function maskSensitiveQueryParams(mixed $queryParams): mixed
    {
        if (! is_array($queryParams)) {
            return $queryParams;
        }

        $masked = [];

        foreach ($queryParams as $key => $value) {
            if (is_string($key) && $this->isSensitiveQueryParam($key)) {
                $masked[$key] = $this->redactionMask();
                continue;
            }

            $masked[$key] = $this->maskSensitiveQueryParams($value);
        }

        return $masked;
    }

    protected function isSensitiveQueryParam(string $field): bool
    {
        $redactedFields = array_map('strtolower', (array) config('context-logging.http.redact_query_params', []));

        return in_array(strtolower($field), $redactedFields, true);
    }

    protected function redactionMask(): string
    {
        $mask = config('context-logging.http.redact_value', '[redacted]');

        return is_string($mask) && $mask !== '' ? $mask : '[redacted]';
    }
}
