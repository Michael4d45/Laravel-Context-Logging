<?php

namespace Michael4d45\ContextLogging;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Illuminate\Http\Client\Request as ClientRequest;
use Illuminate\Http\Client\Response as ClientResponse;

/**
 * Handles outbound HTTP request/response context capture.
 */
class HttpClientInstrumentation
{
    protected bool $registered = false;

    public function __construct(
        protected ContextStore $contextStore,
    ) {}

    /**
     * Enable HTTP request/response recording once.
     */
    public function register(): void
    {
        if ($this->registered) {
            return;
        }

        $this->registered = true;

        // Attach middleware to capture request/response timestamps as close to the actual call as possible.
        Http::globalMiddleware(function (callable $handler) {
            return function ($request, array $options) use ($handler) {
                $requestStart = microtime(true);

                $clientRequest = new ClientRequest($request);
                $url = $clientRequest->url();
                $urlParts = $this->extractUrlParts($url);

                $requestData = [
                    'method' => $clientRequest->method(),
                    'url' => $url,
                    'path' => $urlParts['path'],
                    'query_params' => $this->maskSensitiveQueryParams($urlParts['query_params']),
                    'timestamp' => $requestStart,
                ];

                if ((bool) config('context-logging.http.capture_headers', false)) {
                    $requestData['headers'] = $this->normalizeHeaders($clientRequest->headers());
                }

                if ((bool) config('context-logging.http.capture_body', false)) {
                    $body = $clientRequest->body();

                    if ($body !== null) {
                        $requestData['body'] = $this->decodeAndMaskJsonBody(
                            $clientRequest->header('Content-Type'),
                            $body
                        );
                    }
                }

                $httpCallId = $this->contextStore->beginHttpCall($requestData);

                $options['context']['context_logging_http_call_id'] = $httpCallId;

                $promise = $handler($request, $options);

                return $promise->then(function ($response) use ($httpCallId) {
                    $responseTs = microtime(true);

                    $clientResponse = new ClientResponse($response);
                    $responseData = [
                        'status' => $clientResponse->status(),
                        'timestamp' => $responseTs,
                    ];

                    if ((bool) config('context-logging.http.capture_headers', false)) {
                        $responseData['headers'] = $clientResponse->headers();
                    }

                    if ((bool) config('context-logging.http.capture_body', false)) {
                        $body = $clientResponse->body();

                        if ($body !== null) {
                            $responseData['body'] = $this->decodeAndMaskJsonBody(
                                [$clientResponse->header('Content-Type')],
                                $body
                            );
                        }
                    }

                    $this->contextStore->completeHttpCall($httpCallId, $responseData);

                    return $response;
                });
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