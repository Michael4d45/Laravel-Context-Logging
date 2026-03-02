<?php

namespace Michael4d45\ContextLogging\Tests;

use Michael4d45\ContextLogging\ContextStore;
use PHPUnit\Framework\Attributes\Test;
use Orchestra\Testbench\TestCase;

class ContextStoreTest extends TestCase
{
    protected ContextStore $contextStore;

    protected function setUp(): void
    {
        parent::setUp();
        $this->contextStore = new ContextStore();
    }

    #[Test]
    public function it_accumulates_context_data()
    {
        $this->contextStore->initialize();
        $this->contextStore->addContext('request_id', '123');
        $this->contextStore->addContext('user_id', 42);

        $this->assertEquals('123', $this->contextStore->getContext('request_id'));
        $this->assertEquals(42, $this->contextStore->getContext('user_id'));
        $this->assertNull($this->contextStore->getContext('nonexistent'));
    }

    #[Test]
    public function it_accumulates_log_events()
    {
        $this->contextStore->initialize();

        $this->contextStore->addEvent('info', 'Order placed', ['order_id' => 123]);
        $this->contextStore->addEvent('error', 'Payment failed', ['error' => 'timeout']);

        $events = $this->contextStore->getEvents();

        $this->assertCount(2, $events);
        $this->assertEquals('info', $events[0]['level']);
        $this->assertEquals('Order placed', $events[0]['message']);
        $this->assertEquals(['order_id' => 123], $events[0]['context']);

        $this->assertEquals('error', $events[1]['level']);
        $this->assertEquals('Payment failed', $events[1]['message']);
        $this->assertEquals(['error' => 'timeout'], $events[1]['context']);
    }

    #[Test]
    public function it_generates_structured_payload()
    {
        $this->contextStore->initialize();

        // Add context
        $this->contextStore->addContext('request_id', '123');
        $this->contextStore->addContext('method', 'POST');

        // Add events
        $this->contextStore->addEvent('info', 'Order placed', ['order_id' => 123]);

        // Finalize
        $this->contextStore->finalize(201);

        $payload = $this->contextStore->getPayload();

        $this->assertArrayHasKey('context', $payload);
        $this->assertArrayHasKey('events', $payload);
        $this->assertEquals('123', $payload['context']['request_id']);
        $this->assertEquals('POST', $payload['context']['method']);
        $this->assertEquals(201, $payload['context']['status']);
        $this->assertCount(1, $payload['events']);
    }

    #[Test]
    public function it_tracks_request_duration()
    {
        $this->contextStore->initialize();

        // Simulate some time passing
        usleep(10000); // 10ms

        $this->contextStore->finalize();

        $payload = $this->contextStore->getPayload();

        $this->assertArrayHasKey('duration_ms', $payload['context']);
        $this->assertGreaterThan(0, $payload['context']['duration_ms']);
    }

    #[Test]
    public function it_can_be_cleared()
    {
        $this->contextStore->initialize();
        $this->contextStore->addContext('test', 'value');
        $this->contextStore->addEvent('info', 'test message');

        $this->assertNotEmpty($this->contextStore->getAllContext());
        $this->assertTrue($this->contextStore->hasEvents());

        $this->contextStore->clear();

        $this->assertEmpty($this->contextStore->getAllContext());
        $this->assertFalse($this->contextStore->hasEvents());
    }

    #[Test]
    public function it_tracks_outbound_http_calls()
    {
        $this->contextStore->initialize();

        $id = $this->contextStore->beginHttpCall([
            'method' => 'GET',
            'url' => 'https://api.example.com/orders',
        ]);

        $this->contextStore->addHttpContext($id, [
            'service' => 'orders-api',
        ]);

        usleep(5000);

        $this->contextStore->completeHttpCall($id, [
            'status' => 200,
        ]);

        $calls = $this->contextStore->getHttpCalls();

        $this->assertCount(1, $calls);
        $this->assertSame('GET', $calls[0]['request']['method']);
        $this->assertSame('https://api.example.com/orders', $calls[0]['request']['url']);
        $this->assertSame('orders-api', $calls[0]['context']['service']);
        $this->assertSame(200, $calls[0]['response']['status']);
        $this->assertArrayHasKey('duration_ms', $calls[0]['response']);
        $this->assertGreaterThan(0, $calls[0]['response']['duration_ms']);
    }

    #[Test]
    public function it_adds_http_calls_to_payload_when_present()
    {
        $this->contextStore->initialize();

        $id = $this->contextStore->beginHttpCall([
            'method' => 'POST',
            'url' => 'https://api.example.com/payments',
        ]);

        $this->contextStore->completeHttpCall($id, [
            'status' => 202,
        ]);

        $payload = $this->contextStore->getPayload();

        $this->assertArrayHasKey('http_calls', $payload);
        $this->assertCount(1, $payload['http_calls']);
        $this->assertSame(202, $payload['http_calls'][0]['response']['status']);
    }

    #[Test]
    public function it_omits_http_calls_from_payload_when_none_exist()
    {
        $this->contextStore->initialize();

        $payload = $this->contextStore->getPayload();

        $this->assertArrayNotHasKey('http_calls', $payload);
    }

    #[Test]
    public function it_ignores_http_tracking_when_disabled()
    {
        $disabledStore = new ContextStore(null, false);
        $disabledStore->initialize();

        $id = $disabledStore->beginHttpCall([
            'method' => 'GET',
            'url' => 'https://api.example.com/users',
        ]);

        $disabledStore->addHttpContext('manual-id', ['foo' => 'bar']);
        $disabledStore->completeHttpCall('manual-id', ['status' => 500]);

        $this->assertSame('', $id);
        $this->assertSame([], $disabledStore->getHttpCalls());
        $this->assertArrayNotHasKey('http_calls', $disabledStore->getPayload());
    }
}
