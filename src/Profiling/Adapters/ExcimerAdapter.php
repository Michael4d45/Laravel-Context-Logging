<?php

declare(strict_types=1);

namespace Michael4d45\ContextLogging\Profiling\Adapters;

use Michael4d45\ContextLogging\Profiling\Contracts\ProfilerAdapter;
use Michael4d45\ContextLogging\Profiling\ProfileRef;

/**
 * Stub for Excimer — sampling lifecycle is app-owned; Speedscope export later.
 */
final class ExcimerAdapter implements ProfilerAdapter
{
    public function name(): string
    {
        return 'excimer';
    }

    public function detect(): ?ProfileRef
    {
        return null;
    }
}
