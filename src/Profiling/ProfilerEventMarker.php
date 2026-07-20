<?php

declare(strict_types=1);

namespace Michael4d45\ContextLogging\Profiling;

/**
 * A deliberately visible userland call used to anchor context events inside
 * native profiler call streams. The sequence is also stored on the log event.
 */
final class ProfilerEventMarker
{
    public static function point(int $sequence): int
    {
        return $sequence;
    }
}
