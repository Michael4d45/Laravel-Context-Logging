<?php

namespace Michael4d45\ContextLogging\Tests;

use Michael4d45\ContextLogging\ContextStore;
use Michael4d45\ContextLogging\ContextualLogger;
use PHPUnit\Framework\Attributes\Test;
use Orchestra\Testbench\TestCase;

class ContextualLoggerTest extends TestCase
{
    protected ContextStore $contextStore;
    protected ContextualLogger $logger;

    protected function setUp(): void
    {
        parent::setUp();
        $this->contextStore = new ContextStore();
        $this->contextStore->initialize();
        $this->logger = new ContextualLogger($this->contextStore);
    }

    #[Test]
    public function it_accumulates_log_calls_as_events()
    {
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
        $this->logger->log('warning', 'Generic log message', ['key' => 'value']);

        $events = $this->contextStore->getEvents();
        $this->assertCount(1, $events);
        $this->assertEquals('warning', $events[0]['level']);
        $this->assertEquals('Generic log message', $events[0]['message']);
        $this->assertEquals(['key' => 'value'], $events[0]['context']);
    }
}
