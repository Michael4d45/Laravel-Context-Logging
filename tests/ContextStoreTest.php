<?php

namespace Michael\ContextLogging\Tests;

use Michael\ContextLogging\ContextStore;
use Orchestra\Testbench\TestCase;

class ContextStoreTest extends TestCase
{
    protected ContextStore $contextStore;

    protected function setUp(): void
    {
        parent::setUp();
        $this->contextStore = new ContextStore();
    }

    /** @test */
    public function it_accumulates_context_data()
    {
        $this->contextStore->initialize();
        $this->contextStore->addContext('request_id', '123');
        $this->contextStore->addContext('user_id', 42);

        $this->assertEquals('123', $this->contextStore->getContext('request_id'));
        $this->assertEquals(42, $this->contextStore->getContext('user_id'));
        $this->assertNull($this->contextStore->getContext('nonexistent'));
    }

    /** @test */
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

    /** @test */
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

    /** @test */
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

    /** @test */
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
}
