<?php

namespace Michael4d45\ContextLogging\Tests;

use Michael4d45\ContextLogging\ContextLogEmitter;
use Michael4d45\ContextLogging\ContextStore;
use PHPUnit\Framework\Attributes\Test;

class ContextLogEmitterTest extends TestCase
{
    protected string $logFile;

    protected function setUp(): void
    {
        parent::setUp();

        $this->logFile = tempnam(sys_get_temp_dir(), 'context-log-');

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
    public function it_emits_an_interrupted_request_when_shutdown_happens_before_normal_termination(): void
    {
        $contextStore = $this->app->make(ContextStore::class);
        $contextStore->initialize();
        $contextStore->addContexts([
            'method' => 'GET',
            'path' => 'broken-endpoint',
        ]);

        ContextLogEmitter::emitInterruptedLifecycle($contextStore);

        $this->assertTrue($contextStore->hasBeenEmitted());
        $this->assertStringContainsString('Request interrupted', file_get_contents($this->logFile) ?: '');
    }

    #[Test]
    public function it_records_fatal_shutdown_details_when_present(): void
    {
        $contextStore = $this->app->make(ContextStore::class);
        $contextStore->initialize();
        $contextStore->addContexts([
            'method' => 'POST',
            'path' => 'orders',
        ]);

        ContextLogEmitter::emitInterruptedLifecycle($contextStore, [
            'type' => E_ERROR,
            'message' => 'Call to undefined function boom()',
            'file' => '/tmp/test.php',
            'line' => 12,
        ]);

        $contents = file_get_contents($this->logFile) ?: '';

        $this->assertStringContainsString('Request failed', $contents);
        $this->assertStringContainsString('PHP fatal error', $contents);
        $this->assertStringContainsString('undefined function boom', $contents);
    }
}