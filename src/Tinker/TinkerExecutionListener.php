<?php

namespace Michael4d45\ContextLogging\Tinker;

use Michael4d45\ContextLogging\ContextLogEmitter;
use Michael4d45\ContextLogging\ContextStore;
use Psy\ExecutionLoop\AbstractListener;
use Psy\Shell;

class TinkerExecutionListener extends AbstractListener
{
    protected bool $executionActive = false;

    public function __construct(
        protected ContextStore $contextStore
    ) {}

    public static function isSupported(): bool
    {
        return true;
    }

    public function onExecute(Shell $shell, string $code)
    {
        if (trim($code) === '') {
            return null;
        }

        $this->executionActive = true;
        $this->contextStore->initialize(true);
        $this->contextStore->addContexts([
            'run_id' => (string) \Illuminate\Support\Str::uuid(),
            'timestamp' => now()->toISOString(),
            'command' => 'tinker',
            'source' => 'tinker',
            'mode' => 'interactive',
        ]);

        return null;
    }

    public function afterLoop(Shell $shell)
    {
        if (!$this->executionActive) {
            return;
        }

        ContextLogEmitter::emit($this->contextStore, null, 'Tinker execution completed');
        $this->contextStore->clear();
        $this->executionActive = false;
    }
}