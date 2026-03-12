<?php

namespace Michael4d45\ContextLogging\Tests;

use Illuminate\Console\Events\CommandStarting;
use Michael4d45\ContextLogging\ContextStore;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;

class ConsoleLifecycleTest extends TestCase
{
    protected function getEnvironmentSetUp($app): void
    {
        $app['config']->set('context-logging.log.console', true);
    }

    #[Test]
    public function it_promotes_buffered_events_when_a_console_command_starts(): void
    {
        $contextStore = $this->app->make(ContextStore::class);
        $contextStore->addEvent('info', 'Console kernel booted');

        $this->app['events']->dispatch(new CommandStarting(
            'demo:run',
            new ArrayInput([]),
            new BufferedOutput()
        ));

        $this->assertSame('demo:run', $contextStore->getContext('command'));
        $this->assertCount(1, $contextStore->getEvents());
        $this->assertSame('Console kernel booted', $contextStore->getEvents()[0]['message']);
        $this->assertSame([], $contextStore->getBufferedEvents());
    }

    #[Test]
    public function it_clears_buffered_events_for_skipped_console_commands(): void
    {
        config()->set('context-logging.console.skip_commands', ['queue:*']);

        $contextStore = $this->app->make(ContextStore::class);
        $contextStore->addEvent('info', 'Worker booted');

        $this->app['events']->dispatch(new CommandStarting(
            'queue:work',
            new ArrayInput([]),
            new BufferedOutput()
        ));

        $this->assertSame([], $contextStore->getEvents());
        $this->assertSame([], $contextStore->getBufferedEvents());
        $this->assertSame([], $contextStore->getAllContext());
        $this->assertFalse($contextStore->hasEvents());
    }
}
