<?php

namespace Michael4d45\ContextLogging\Tests;

use Illuminate\Support\Facades\Http;
use Michael4d45\ContextLogging\ContextStore;
use Michael4d45\ContextLogging\HttpClientInstrumentation;
use Michael4d45\ContextLogging\HttpContextHooks;
use PHPUnit\Framework\Attributes\Test;

class HttpClientInstrumentationTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config()->set('context-logging.http.enabled', true);
        $this->app->make(HttpClientInstrumentation::class)->register();

        HttpContextHooks::clear();
    }

    protected function tearDown(): void
    {
        HttpContextHooks::clear();
        parent::tearDown();
    }

    #[Test]
    public function it_globally_captures_outbound_http_without_manual_wrapping(): void
    {
        Http::fake([
            '*' => Http::response(['ok' => true], 200),
        ]);

        $store = $this->app->make(ContextStore::class);
        $store->initialize();

        Http::get('https://api.example.com/ping');

        $calls = $store->getHttpCalls();

        $this->assertCount(1, $calls);
        $this->assertSame('GET', $calls[0]['request']['method']);
        $this->assertSame('https://api.example.com/ping', $calls[0]['request']['url']);
        $this->assertSame(200, $calls[0]['response']['status']);
        $this->assertArrayNotHasKey('body', $calls[0]['response']);
    }

    #[Test]
    public function it_applies_registered_hooks_during_global_capture(): void
    {
        Http::fake([
            '*' => Http::response(['ok' => true], 202),
        ]);

        HttpContextHooks::beforeRequest(function (array $payload): array {
            $payload['request']['service'] = 'billing';

            return $payload;
        });

        HttpContextHooks::afterResponse(function (array $payload): array {
            $payload['response']['classified_as'] = 'accepted';

            return $payload;
        });

        $store = $this->app->make(ContextStore::class);
        $store->initialize();

        $this->app->make(HttpClientInstrumentation::class)->register();

        Http::post('https://api.example.com/bill', ['amount' => 10]);

        $calls = $store->getHttpCalls();

        $this->assertCount(1, $calls);
        $this->assertSame('billing', $calls[0]['request']['service']);
        $this->assertSame('accepted', $calls[0]['response']['classified_as']);
    }

    #[Test]
    public function it_captures_request_and_response_body_when_enabled(): void
    {
        config()->set('context-logging.http.capture_body', true);

        Http::fake([
            '*' => Http::response(['ok' => true], 201),
        ]);

        $store = $this->app->make(ContextStore::class);
        $store->initialize();

        $this->app->make(HttpClientInstrumentation::class)->register();

        Http::post('https://api.example.com/create', ['amount' => 10]);

        $calls = $store->getHttpCalls();

        $this->assertCount(1, $calls);
        $this->assertArrayHasKey('body', $calls[0]['request']);
        $this->assertIsArray($calls[0]['request']['body']);
        $this->assertSame(10, $calls[0]['request']['body']['amount']);
        $this->assertArrayHasKey('body', $calls[0]['response']);
        $this->assertIsArray($calls[0]['response']['body']);
        $this->assertSame(true, $calls[0]['response']['body']['ok']);
    }

    #[Test]
    public function it_redacts_sensitive_fields_in_json_bodies(): void
    {
        config()->set('context-logging.http.capture_body', true);
        config()->set('context-logging.http.redact_body_fields', ['password', 'token']);

        Http::fake([
            '*' => Http::response([
                'ok' => true,
                'token' => 'server-token',
                'profile' => [
                    'password' => 'server-secret',
                ],
            ], 200),
        ]);

        $store = $this->app->make(ContextStore::class);
        $store->initialize();

        $this->app->make(HttpClientInstrumentation::class)->register();

        Http::post('https://api.example.com/login', [
            'email' => 'user@example.com',
            'password' => 'client-secret',
            'meta' => [
                'token' => 'client-token',
            ],
        ]);

        $calls = $store->getHttpCalls();

        $this->assertCount(1, $calls);
        $this->assertSame('[redacted]', $calls[0]['request']['body']['password']);
        $this->assertSame('[redacted]', $calls[0]['request']['body']['meta']['token']);
        $this->assertSame('user@example.com', $calls[0]['request']['body']['email']);
        $this->assertSame('[redacted]', $calls[0]['response']['body']['token']);
        $this->assertSame('[redacted]', $calls[0]['response']['body']['profile']['password']);
    }

    #[Test]
    public function it_uses_custom_redaction_marker_from_config(): void
    {
        config()->set('context-logging.http.capture_body', true);
        config()->set('context-logging.http.capture_headers', true);
        config()->set('context-logging.http.redact_value', '***');
        config()->set('context-logging.http.redact_headers', ['authorization']);
        config()->set('context-logging.http.redact_body_fields', ['token']);

        Http::fake([
            '*' => Http::response([
                'token' => 'response-secret',
            ], 200, [
                'Content-Type' => 'application/json',
                'Authorization' => 'Bearer response-token',
            ]),
        ]);

        $store = $this->app->make(ContextStore::class);
        $store->initialize();

        $this->app->make(HttpClientInstrumentation::class)->register();

        Http::withHeaders([
            'Authorization' => 'Bearer request-token',
        ])->post('https://api.example.com/secure', [
            'token' => 'request-secret',
        ]);

        $calls = $store->getHttpCalls();

        $this->assertCount(1, $calls);
        $this->assertSame('***', $calls[0]['request']['headers']['authorization']);
        $this->assertSame('***', $calls[0]['request']['body']['token']);
        $this->assertSame('***', $calls[0]['response']['body']['token']);
    }

    #[Test]
    public function it_captures_path_and_query_params_separately(): void
    {
        Http::fake([
            '*' => Http::response(['ok' => true], 200),
        ]);

        $store = $this->app->make(ContextStore::class);
        $store->initialize();

        $this->app->make(HttpClientInstrumentation::class)->register();

        Http::get('https://api.example.com/users/list?page=2&sort=name');

        $calls = $store->getHttpCalls();

        $this->assertCount(1, $calls);
        $this->assertSame('/users/list', $calls[0]['request']['path']);
        $this->assertSame('2', $calls[0]['request']['query_params']['page']);
        $this->assertSame('name', $calls[0]['request']['query_params']['sort']);
    }

    #[Test]
    public function it_redacts_sensitive_query_params(): void
    {
        config()->set('context-logging.http.redact_query_params', ['token', 'signature']);

        Http::fake([
            '*' => Http::response(['ok' => true], 200),
        ]);

        $store = $this->app->make(ContextStore::class);
        $store->initialize();

        $this->app->make(HttpClientInstrumentation::class)->register();

        Http::get('https://api.example.com/callback?token=secret&signature=abc123&page=1');

        $calls = $store->getHttpCalls();

        $this->assertCount(1, $calls);
        $this->assertSame('[redacted]', $calls[0]['request']['query_params']['token']);
        $this->assertSame('[redacted]', $calls[0]['request']['query_params']['signature']);
        $this->assertSame('1', $calls[0]['request']['query_params']['page']);
    }
}
