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
    public function it_promotes_buffered_events_into_the_request_context(): void
    {
        $contextStore = new ContextStore();
        $contextStore->addEvent('info', 'Broadcasting channels loaded');

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
}
