<?php

namespace Michael4d45\ContextLogging\Tests;

use Illuminate\Http\Request;
use Michael4d45\ContextLogging\ContextStore;
use Michael4d45\ContextLogging\Middleware\RequestContextMiddleware;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\HttpFoundation\Response;

class RequestContextMiddlewareTest extends TestCase
{
    #[Test]
    public function it_promotes_buffered_pre_middleware_events_into_the_request_context(): void
    {
        // Subclass simulates HTTP (pre-lifecycle buffering); PHPUnit runs in the console so the default store would emit standalone.
        $contextStore = new class extends ContextStore {
            protected function shouldBufferPreLifecycleEvents(): bool
            {
                return true;
            }
        };

        $contextStore->addEvent('info', 'Broadcasting channels loaded');

        $this->assertCount(1, $contextStore->getBufferedEvents());

        $middleware = new RequestContextMiddleware($contextStore);
        $request = Request::create('https://example.test/broadcasting/auth?socket_id=123', 'POST');

        $response = $middleware->handle($request, static fn () => new Response('ok'));

        $this->assertSame('ok', $response->getContent());
        $this->assertSame('POST', $contextStore->getContext('method'));
        $this->assertSame('broadcasting/auth', $contextStore->getContext('path'));
        $this->assertSame('https://example.test/broadcasting/auth?socket_id=123', $contextStore->getContext('full_url'));
        $this->assertCount(1, $contextStore->getEvents());
        $this->assertSame('Broadcasting channels loaded', $contextStore->getEvents()[0]['message']);
        $this->assertSame([], $contextStore->getBufferedEvents());
    }

    #[Test]
    public function it_adds_incoming_request_for_empty_get_when_request_logging_enabled(): void
    {
        config()->set('context-logging.log.request', true);

        $contextStore = new ContextStore;
        $middleware = new RequestContextMiddleware($contextStore);
        $request = Request::create('https://example.test/ingredient-parser-flow', 'GET');

        $middleware->handle($request, static fn () => new Response('ok'));

        $events = $contextStore->getEvents();
        $this->assertCount(1, $events);
        $this->assertSame('Incoming Request', $events[0]['message']);
        $this->assertArrayHasKey('headers', $events[0]['context']);
        $this->assertArrayHasKey('cookies', $events[0]['context']);
        $this->assertArrayNotHasKey('body', $events[0]['context']);
        $this->assertArrayNotHasKey('query_params', $events[0]['context']);
    }

    #[Test]
    public function it_includes_body_and_query_on_incoming_request_when_present(): void
    {
        config()->set('context-logging.log.request', true);

        $contextStore = new ContextStore;
        $middleware = new RequestContextMiddleware($contextStore);
        $request = Request::create(
            'https://example.test/items?page=2',
            'POST',
            ['name' => 'flour']
        );

        $middleware->handle($request, static fn () => new Response('ok'));

        $event = $contextStore->getEvents()[0];
        $this->assertSame('Incoming Request', $event['message']);
        $this->assertSame('flour', $event['context']['body']['name']);
        $this->assertSame('2', $event['context']['query_params']['page']);
    }

    #[Test]
    public function it_skips_incoming_request_when_request_logging_disabled(): void
    {
        config()->set('context-logging.log.request', false);

        $contextStore = new ContextStore;
        $middleware = new RequestContextMiddleware($contextStore);
        $request = Request::create('https://example.test/items', 'POST', ['name' => 'flour']);

        $middleware->handle($request, static fn () => new Response('ok'));

        $this->assertSame([], $contextStore->getEvents());
    }
}
