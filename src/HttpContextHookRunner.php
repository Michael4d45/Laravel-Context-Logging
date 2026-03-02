<?php

namespace Michael4d45\ContextLogging;

use Throwable;

/**
 * Executes configured HTTP context hooks for outbound request/response enrichment.
 */
class HttpContextHookRunner
{
    /**
     * Run hooks for outbound HTTP request context before storage.
     */
    public function runBeforeRequest(array $request): array
    {
        $hooks = HttpContextHooks::getBeforeRequestHooks();

        return $this->runHooks((array) $hooks, [
            'request' => $request,
        ], 'request');
    }

    /**
     * Run hooks for outbound HTTP response context before storage.
     */
    public function runAfterResponse(array $request, array $response, array $context = []): array
    {
        $hooks = HttpContextHooks::getAfterResponseHooks();

        return $this->runHooks((array) $hooks, [
            'request' => $request,
            'response' => $response,
            'context' => $context,
        ], 'response');
    }

    /**
     * @param array<int, mixed> $hooks
     */
    protected function runHooks(array $hooks, array $payload, string $target): array
    {
        $errors = [];

        foreach ($hooks as $hook) {
            try {
                $result = $hook($payload);

                if (is_array($result) && array_key_exists($target, $result) && is_array($result[$target])) {
                    $payload[$target] = $result[$target];
                    continue;
                }

                if (is_array($result)) {
                    $payload[$target] = $result;
                }
            } catch (Throwable $exception) {
                $errors[] = [
                    'hook' => is_string($hook) ? $hook : get_debug_type($hook),
                    'message' => $exception->getMessage(),
                ];
            }
        }

        if (!empty($errors)) {
            $payload[$target]['_hook_errors'] = $errors;
        }

        return $payload[$target];
    }
}