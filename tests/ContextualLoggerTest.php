<?php

namespace Michael4d45\ContextLogging\Tests;

use Michael4d45\ContextLogging\ContextStore;
use Michael4d45\ContextLogging\ContextualLogger;
use PHPUnit\Framework\Attributes\Test;

class ContextualLoggerTest extends TestCase
{
    protected ContextStore $contextStore;
    protected ContextualLogger $logger;

    protected function setUp(): void
    {
        parent::setUp();
        $this->contextStore = new ContextStore;
        $this->contextStore->initialize();
        $this->logger = new ContextualLogger($this->contextStore);
    }

    #[Test]
    public function it_accumulates_log_calls_as_events()
    {
        config()->set('context-logging.profiling.log_traces', false);

        $this->logger->info('User logged in', ['user_id' => 123]);
        $this->logger->error('Database connection failed', ['error' => 'timeout']);

        $events = $this->contextStore->getEvents();

        $this->assertCount(2, $events);
        $this->assertEquals('info', $events[0]['level']);
        $this->assertEquals('User logged in', $events[0]['message']);
        $this->assertEquals(['user_id' => 123], $events[0]['context']);

        $this->assertEquals('error', $events[1]['level']);
        $this->assertEquals('Database connection failed', $events[1]['message']);
        $this->assertEquals(['error' => 'timeout'], $events[1]['context']);
    }

    #[Test]
    public function it_supports_all_log_levels()
    {
        config()->set('context-logging.profiling.log_traces', false);

        $levels = ['emergency', 'alert', 'critical', 'error', 'warning', 'notice', 'info', 'debug'];

        foreach ($levels as $level) {
            $this->logger->$level("Test $level message");
        }

        $events = $this->contextStore->getEvents();
        $this->assertCount(8, $events);

        foreach ($levels as $index => $level) {
            $this->assertEquals($level, $events[$index]['level']);
            $this->assertEquals("Test $level message", $events[$index]['message']);
        }
    }

    #[Test]
    public function it_handles_stringable_messages()
    {
        config()->set('context-logging.profiling.log_traces', false);

        $message = new class implements \Stringable {
            public function __toString(): string
            {
                return 'Stringable message';
            }
        };

        $this->logger->info($message);

        $events = $this->contextStore->getEvents();
        $this->assertEquals('Stringable message', $events[0]['message']);
    }

    #[Test]
    public function it_handles_generic_log_method()
    {
        config()->set('context-logging.profiling.log_traces', false);

        $this->logger->log('warning', 'Generic log message', ['key' => 'value']);

        $events = $this->contextStore->getEvents();
        $this->assertEquals('warning', $events[0]['level']);
        $this->assertEquals('Generic log message', $events[0]['message']);
        $this->assertEquals(['key' => 'value'], $events[0]['context']);
    }

    #[Test]
    public function it_attaches_collapsed_trace_to_log_events_by_default(): void
    {
        config()->set('context-logging.profiling.log_traces', true);
        config()->set('context-logging.profiling.log_trace_min_level', 'debug');

        $this->logger->info('Order placed', ['order_id' => 1]);

        $events = $this->contextStore->getEvents();
        $this->assertArrayHasKey('trace', $events[0]['context']);
        $this->assertIsArray($events[0]['context']['trace']);
        $this->assertSame(1, $events[0]['context']['order_id']);
    }

    #[Test]
    public function it_preserves_caller_supplied_trace(): void
    {
        config()->set('context-logging.profiling.log_traces', true);

        $this->logger->info('Custom', ['trace' => ['App\\Foo.php:10']]);

        $events = $this->contextStore->getEvents();
        $this->assertSame(['App\\Foo.php:10'], $events[0]['context']['trace']);
    }

    #[Test]
    public function it_skips_log_traces_when_disabled(): void
    {
        config()->set('context-logging.profiling.log_traces', false);

        $this->logger->info('No trace');

        $events = $this->contextStore->getEvents();
        $this->assertArrayNotHasKey('trace', $events[0]['context']);
    }

    #[Test]
    public function it_respects_log_trace_min_level(): void
    {
        config()->set('context-logging.profiling.log_traces', true);
        config()->set('context-logging.profiling.log_trace_min_level', 'error');

        $this->logger->info('below threshold');
        $this->logger->error('at threshold');

        $events = $this->contextStore->getEvents();
        $this->assertArrayNotHasKey('trace', $events[0]['context']);
        $this->assertArrayHasKey('trace', $events[1]['context']);
    }
}
