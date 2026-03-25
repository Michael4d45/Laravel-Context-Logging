<?php

namespace Michael4d45\ContextLogging\Tests;

use Michael4d45\ContextLogging\ContextStore;
use Michael4d45\ContextLogging\Tinker\TinkerExecutionListener;
use PHPUnit\Framework\Attributes\Test;
use Psy\Configuration;
use Psy\Shell;

class TinkerExecutionListenerTest extends TestCase
{
    protected string $logFile;

    protected function setUp(): void
    {
        parent::setUp();

        $this->logFile = tempnam(sys_get_temp_dir(), 'context-tinker-log-');

        config()->set('logging.default', 'single');
        config()->set('logging.channels.single', [
            'driver' => 'single',
            'path' => $this->logFile,
            'replace_placeholders' => true,
        ]);
    }

    protected function tearDown(): void
    {
        if (is_file($this->logFile)) {
            unlink($this->logFile);
        }

        parent::tearDown();
    }

    #[Test]
    public function it_emits_after_each_tinker_execution_loop(): void
    {
        if (!class_exists(Shell::class)) {
            $this->markTestSkipped('PsySH is not installed.');
        }

        $config = new Configuration();
        $config->setUsePcntl(false);

        $shell = new Shell($config);
        $contextStore = $this->app->make(ContextStore::class);
        $listener = new TinkerExecutionListener($contextStore);

        $listener->onExecute($shell, 'Log::info("hello");');
        $contextStore->addEvent('info', 'hello from tinker');

        $this->assertSame('tinker', $contextStore->getContext('command'));
        $this->assertSame('tinker', $contextStore->getContext('source'));

        $listener->afterLoop($shell);

        $this->assertFalse($contextStore->hasLifecycleStarted());
        $this->assertSame([], $contextStore->getAllContext());
        $this->assertStringContainsString('Tinker execution completed', file_get_contents($this->logFile) ?: '');
    }
}