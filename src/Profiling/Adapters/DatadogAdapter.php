<?php

declare(strict_types=1);

namespace Michael4d45\ContextLogging\Profiling\Adapters;

use Michael4d45\ContextLogging\Profiling\Contracts\ProfilerAdapter;
use Michael4d45\ContextLogging\Profiling\ProfileRef;

/**
 * Stub for Datadog APM/profiler correlation — deferred beyond local-first v1.
 */
final class DatadogAdapter implements ProfilerAdapter
{
    public function name(): string
    {
        return 'datadog';
    }

    public function detect(): ?ProfileRef
    {
        return null;
    }
}
