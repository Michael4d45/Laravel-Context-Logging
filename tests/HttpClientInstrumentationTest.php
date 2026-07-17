<?php

namespace Michael4d45\ContextLogging\Tests;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use Illuminate\Support\Facades\Http;
use Michael4d45\ContextLogging\ContextStore;
use Michael4d45\ContextLogging\Guzzle\ClientPatch;
use Michael4d45\ContextLogging\HttpClientInstrumentation;
use Michael4d45\ContextLogging\HttpContextHooks;
use PHPUnit\Framework\Attributes\Test;

class HttpClientInstrumentationTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config()->set('context-logging.http.enabled', true);
        config()->set('context-logging.http.guzzle_patch', false);
        ClientPatch::force(null);
        $this->app->make(HttpClientInstrumentation::class)->register();

        HttpContextHooks::clear();
    }

    protected function tearDown(): void
    {
        HttpContextHooks::clear();
        ClientPatch::force(null);
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

    #[Test]
    public function it_instruments_raw_guzzle_clients_via_create_client(): void
    {
        $store = $this->app->make(ContextStore::class);
        $store->initialize();

        $mock = new MockHandler([
            new Response(204),
        ]);

        $client = $this->app->make(HttpClientInstrumentation::class)->createClient([
            'handler' => HandlerStack::create($mock),
        ]);

        $client->get('https://api.example.com/v1/ping');

        $calls = $store->getHttpCalls();

        $this->assertCount(1, $calls);
        $this->assertSame('GET', $calls[0]['request']['method']);
        $this->assertSame(204, $calls[0]['response']['status']);
        $this->assertArrayHasKey('duration_ms', $calls[0]['response']);
    }

    #[Test]
    public function it_pushes_onto_an_existing_handler_stack(): void
    {
        $store = $this->app->make(ContextStore::class);
        $store->initialize();

        $mock = new MockHandler([
            new Response(200, [], '{"ok":true}'),
        ]);
        $stack = HandlerStack::create($mock);

        $this->app->make(HttpClientInstrumentation::class)->pushOnto($stack);

        $client = new Client(['handler' => $stack]);
        $client->get('https://orders.example.com/orders');

        $calls = $store->getHttpCalls();

        $this->assertCount(1, $calls);
        $this->assertSame('https://orders.example.com/orders', $calls[0]['request']['url']);
        $this->assertSame(200, $calls[0]['response']['status']);
    }

    #[Test]
    public function it_tags_service_from_request_host(): void
    {
        $store = $this->app->make(ContextStore::class);
        $store->initialize();

        $mock = new MockHandler([
            new Response(200),
        ]);

        $client = $this->app->make(HttpClientInstrumentation::class)->createClient([
            'handler' => HandlerStack::create($mock),
        ]);

        $client->get('https://api.example.com/api/exams');

        $calls = $store->getHttpCalls();

        $this->assertCount(1, $calls);
        $this->assertSame('api.example.com', $calls[0]['request']['service']);
    }

    #[Test]
    public function it_completes_http_calls_on_transport_failures(): void
    {
        $store = $this->app->make(ContextStore::class);
        $store->initialize();

        $request = new Request('GET', 'https://api.example.com/timeout');
        $mock = new MockHandler([
            new ConnectException('Connection timed out', $request),
        ]);

        $client = $this->app->make(HttpClientInstrumentation::class)->createClient([
            'handler' => HandlerStack::create($mock),
            'http_errors' => false,
        ]);

        try {
            $client->get('https://api.example.com/timeout');
            $this->fail('Expected ConnectException');
        } catch (ConnectException $e) {
            $this->assertSame('Connection timed out', $e->getMessage());
        }

        $calls = $store->getHttpCalls();

        $this->assertCount(1, $calls);
        $this->assertSame(0, $calls[0]['response']['status']);
        $this->assertSame('Connection timed out', $calls[0]['response']['error']);
        $this->assertSame(ConnectException::class, $calls[0]['response']['error_class']);
        $this->assertArrayHasKey('duration_ms', $calls[0]['response']);
    }

    #[Test]
    public function it_falls_back_to_http_middleware_when_guzzle_patch_missing(): void
    {
        if (ClientPatch::isClientPatched()) {
            $this->markTestSkipped('Guzzle Client patch is installed in this environment.');
        }

        config()->set('context-logging.http.enabled', true);
        config()->set('context-logging.http.guzzle_patch', true);

        $factory = new \Illuminate\Http\Client\Factory;
        Http::swap($factory);

        $instrumentation = new HttpClientInstrumentation($this->app->make(ContextStore::class));
        $instrumentation->register();

        $this->assertTrue($instrumentation->guzzlePatchEnabled());
        // Patch not installed → keep facade middleware so Http:: is still captured.
        $this->assertNotSame([], $factory->getGlobalMiddleware());
    }

    #[Test]
    public function it_skips_http_facade_middleware_when_guzzle_client_is_patched(): void
    {
        if (! ClientPatch::isClientPatched()) {
            $this->markTestSkipped('Guzzle Client patch is not installed in this environment.');
        }

        config()->set('context-logging.http.enabled', true);
        config()->set('context-logging.http.guzzle_patch', true);

        $factory = new \Illuminate\Http\Client\Factory;
        Http::swap($factory);

        $instrumentation = new HttpClientInstrumentation($this->app->make(ContextStore::class));
        $instrumentation->register();

        $this->assertSame([], $factory->getGlobalMiddleware());
    }

    #[Test]
    public function client_patch_apply_is_noop_when_inactive(): void
    {
        ClientPatch::force(false);

        $config = ['timeout' => 5];
        $this->assertSame($config, ClientPatch::apply($config));
    }

    #[Test]
    public function client_patch_apply_instruments_when_forced_active(): void
    {
        ClientPatch::force(true);
        config()->set('context-logging.http.enabled', true);

        $config = ClientPatch::apply([]);

        $this->assertArrayHasKey('handler', $config);
        $this->assertInstanceOf(HandlerStack::class, $config['handler']);
    }
}
