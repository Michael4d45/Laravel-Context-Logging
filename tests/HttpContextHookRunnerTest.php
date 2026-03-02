<?php

namespace Michael4d45\ContextLogging\Tests;

use Michael4d45\ContextLogging\HttpContextHooks;
use Michael4d45\ContextLogging\HttpContextHookRunner;
use PHPUnit\Framework\Attributes\Test;

class HttpContextHookRunnerTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        HttpContextHooks::clear();
    }

    protected function tearDown(): void
    {
        HttpContextHooks::clear();
        parent::tearDown();
    }

    #[Test]
    public function it_runs_before_request_hooks_in_order()
    {
        HttpContextHooks::beforeRequest(function (array $payload): array {
            $payload['request']['tags'][] = 'first';

            return $payload;
        });

        HttpContextHooks::beforeRequest(function (array $payload): array {
            $payload['request']['tags'][] = 'second';

            return $payload;
        });

        $runner = $this->app->make(HttpContextHookRunner::class);

        $result = $runner->runBeforeRequest([
            'method' => 'GET',
            'url' => 'https://api.example.com/health',
        ]);

        $this->assertSame(['first', 'second'], $result['tags']);
    }

    #[Test]
    public function it_runs_after_response_hooks_and_preserves_resilience_on_errors()
    {
        HttpContextHooks::afterResponse(function (array $payload): array {
            $payload['response']['classified_as'] = 'success';

            return $payload;
        });

        HttpContextHooks::afterResponse(function (array $payload): array {
            throw new \RuntimeException('boom');
        });

        $runner = $this->app->make(HttpContextHookRunner::class);

        $result = $runner->runAfterResponse(
            ['method' => 'GET', 'url' => 'https://api.example.com/health'],
            ['status' => 200],
            []
        );

        $this->assertSame('success', $result['classified_as']);
        $this->assertArrayHasKey('_hook_errors', $result);
        $this->assertSame('boom', $result['_hook_errors'][0]['message']);
    }
}
