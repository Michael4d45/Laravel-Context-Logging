<?php

declare(strict_types=1);

namespace Michael4d45\ContextLogging\Profiling\Contracts;

use Michael4d45\ContextLogging\Profiling\ProfileRef;

interface ProfilerAdapter
{
    public function name(): string;

    /**
     * Return a profile reference when this profiler is present and relevant
     * for the current lifecycle; otherwise null.
     */
    public function detect(): ?ProfileRef;
}
