<?php

namespace Michael4d45\ContextLogging;

use Illuminate\Http\Client\Factory as HttpFactory;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\StreamInterface;

/**
 * Registers global HTTP middleware that auto-captures outbound request/response context.
 */
class HttpClientInstrumentation
{
    protected bool $registered = false;

    public function __construct(
        protected HttpFactory $http,
        protected ContextStore $contextStore,
    ) {}

    /**
     * Register global middleware once.
     */
    public function register(): void
    {
        if ($this->registered) {
            return;
        }

        $this->registered = true;

        $this->http->globalMiddleware(function (callable $handler) {
            return function (RequestInterface $request, array $options) use ($handler) {
                $url = (string) $request->getUri();
                $urlParts = $this->extractUrlParts($url);

                $requestData = [
                    'method' => $request->getMethod(),
                    'url' => $url,
                    'path' => $urlParts['path'],
                    'query_params' => $this->maskSensitiveQueryParams($urlParts['query_params']),
                ];

                if ((bool) config('context-logging.http.capture_headers', false)) {
                    $requestData['headers'] = $this->normalizeHeaders($request->getHeaders());
                }

                if ((bool) config('context-logging.http.capture_body', false)) {
                    $body = $this->readBody($request->getBody());

                    if ($body !== null) {
                        $requestData['body'] = $this->decodeAndMaskJsonBody(
                            $request->getHeader('Content-Type'),
                            $body
                        );
                    }
                }

                $httpCallId = $this->contextStore->beginHttpCall($requestData);

                return $handler($request, $options)->then(
                    function (ResponseInterface $response) use ($httpCallId) {
                        $responseData = [
                            'status' => $response->getStatusCode(),
                        ];

                        if ((bool) config('context-logging.http.capture_headers', false)) {
                            $responseData['headers'] = $this->normalizeHeaders($response->getHeaders());
                        }

                        if ((bool) config('context-logging.http.capture_body', false)) {
                            $body = $this->readBody($response->getBody());

                            if ($body !== null) {
                                $responseData['body'] = $this->decodeAndMaskJsonBody(
                                    $response->getHeader('Content-Type'),
                                    $body
                                );
                            }
                        }

                        $this->contextStore->completeHttpCall($httpCallId, $responseData);

                        return $response;
                    },
                    function (mixed $reason) use ($httpCallId) {
                        $message = $reason instanceof \Throwable
                            ? $reason->getMessage()
                            : (string) $reason;

                        $this->contextStore->completeHttpCall($httpCallId, [
                            'status' => 0,
                            'error' => $message,
                        ]);

                        if ($reason instanceof \Throwable) {
                            throw $reason;
                        }

                        throw new \RuntimeException($message);
                    }
                );
            };
        });
    }

    /**
     * @param array<string, array<int, string>> $headers
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
     * Safely read a stream body while restoring the original cursor position.
     */
    protected function readBody(StreamInterface $stream): ?string
    {
        if (!$stream->isReadable()) {
            return null;
        }

        $position = null;

        try {
            if ($stream->isSeekable()) {
                $position = $stream->tell();
                $stream->rewind();
            }

            $contents = $stream->getContents();

            if ($stream->isSeekable() && $position !== null) {
                $stream->seek($position);
            }

            return $contents;
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * Decode JSON response body when applicable, otherwise return raw body.
     */
    protected function decodeAndMaskJsonBody(array $contentTypeHeaders, string $body): mixed
    {
        if (!$this->isJsonByContentType($contentTypeHeaders)) {
            return $body;
        }

        $decoded = json_decode($body, true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            return $body;
        }

        return $this->maskSensitiveBodyData($decoded);
    }

    /**
     * Detect JSON payloads from content type headers.
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

    /**
     * Recursively mask configured sensitive keys in decoded JSON payloads.
     */
    protected function maskSensitiveBodyData(mixed $payload): mixed
    {
        if (!is_array($payload)) {
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

    /**
     * Match body keys case-insensitively against configured redaction list.
     */
    protected function isSensitiveBodyField(string $field): bool
    {
        $redactedFields = array_map('strtolower', (array) config('context-logging.http.redact_body_fields', []));

        return in_array(strtolower($field), $redactedFields, true);
    }

    /**
     * Parse URL into path and query params.
     *
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

    /**
     * Recursively mask configured sensitive query parameters.
     */
    protected function maskSensitiveQueryParams(mixed $queryParams): mixed
    {
        if (!is_array($queryParams)) {
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

    /**
     * Match query keys case-insensitively against configured redaction list.
     */
    protected function isSensitiveQueryParam(string $field): bool
    {
        $redactedFields = array_map('strtolower', (array) config('context-logging.http.redact_query_params', []));

        return in_array(strtolower($field), $redactedFields, true);
    }

    /**
     * Redaction marker used for sensitive values.
     */
    protected function redactionMask(): string
    {
        $mask = config('context-logging.http.redact_value', '[redacted]');

        return is_string($mask) && $mask !== '' ? $mask : '[redacted]';
    }
}