<?php

namespace Michael4d45\ContextLogging\Tinker;

use Michael4d45\ContextLogging\ContextStore;
use Psy\Configuration;
use Psy\Shell;

class ContextLoggingTinkerShell extends Shell
{
    public function __construct(
        protected ContextStore $contextStore,
        ?Configuration $config = null
    ) {
        parent::__construct($config);
    }

    protected function getDefaultLoopListeners(): array
    {
        return [
            ...parent::getDefaultLoopListeners(),
            new TinkerExecutionListener($this->contextStore),
        ];
    }
}