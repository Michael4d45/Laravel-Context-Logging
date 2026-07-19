<?php

declare(strict_types=1);

namespace Michael4d45\ContextLogging\Profiling\Adapters;

use Michael4d45\ContextLogging\Profiling\Contracts\ProfilerAdapter;
use Michael4d45\ContextLogging\Profiling\ProfileRef;

/**
 * Stub for Tideways XHProf — enable/disable is app-owned; no standard run ID.
 */
final class TidewaysXhprofAdapter implements ProfilerAdapter
{
    public function name(): string
    {
        return 'tideways_xhprof';
    }

    public function detect(): ?ProfileRef
    {
        return null;
    }
}
