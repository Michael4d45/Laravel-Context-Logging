<?php

namespace Michael4d45\ContextLogging\Tests;

use Illuminate\Console\Events\CommandStarting;
use Michael4d45\ContextLogging\ContextStore;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;

class ConsoleLifecycleTest extends TestCase
{
    protected string $logFile = '';

    protected function getEnvironmentSetUp($app): void
    {
        $app['config']->set('context-logging.log.console', true);
    }

    protected function tearDown(): void
    {
        if ($this->logFile !== '' && is_file($this->logFile)) {
            unlink($this->logFile);
        }

        parent::tearDown();
    }

    #[Test]
    public function it_emits_pre_command_events_standalone_and_starts_console_context_without_them(): void
    {
        $this->logFile = tempnam(sys_get_temp_dir(), 'console-lifecycle-test-');
        config()->set('logging.default', 'single');
        config()->set('logging.channels.single', [
            'driver' => 'single',
            'path' => $this->logFile,
            'replace_placeholders' => true,
        ]);

        $contextStore = $this->app->make(ContextStore::class);
        $contextStore->addEvent('info', 'Console kernel booted');

        $this->assertStringContainsString('Console kernel booted', file_get_contents($this->logFile) ?: '');

        $this->app['events']->dispatch(new CommandStarting(
            'demo:run',
            new ArrayInput([]),
            new BufferedOutput()
        ));

        $this->assertSame('demo:run', $contextStore->getContext('command'));
        $this->assertCount(0, $contextStore->getEvents());
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

    #[Test]
    public function it_skips_tinker_from_command_wide_console_wrapping(): void
    {
        $contextStore = $this->app->make(ContextStore::class);
        $contextStore->addEvent('info', 'Console kernel booted');

        $this->app['events']->dispatch(new CommandStarting(
            'tinker',
            new ArrayInput([]),
            new BufferedOutput()
        ));

        $this->assertSame([], $contextStore->getEvents());
        $this->assertSame([], $contextStore->getBufferedEvents());
        $this->assertSame([], $contextStore->getAllContext());
        $this->assertFalse($contextStore->hasLifecycleStarted());
    }
}
