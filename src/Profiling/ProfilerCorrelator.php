<?php

declare(strict_types=1);

namespace Michael4d45\ContextLogging\Profiling;

use Michael4d45\ContextLogging\Profiling\Adapters\BlackfireAdapter;
use Michael4d45\ContextLogging\Profiling\Adapters\DatadogAdapter;
use Michael4d45\ContextLogging\Profiling\Adapters\ExcimerAdapter;
use Michael4d45\ContextLogging\Profiling\Adapters\SpxAdapter;
use Michael4d45\ContextLogging\Profiling\Adapters\TidewaysXhprofAdapter;
use Michael4d45\ContextLogging\Profiling\Adapters\XdebugAdapter;
use Michael4d45\ContextLogging\Profiling\Contracts\ProfilerAdapter;

/**
 * Runs configured profiler adapters and returns detected profile refs.
 */
final class ProfilerCorrelator
{
    /**
     * @param  list<ProfilerAdapter>|null  $adapters
     */
    public function __construct(
        private readonly ?array $adapters = null,
    ) {}

    /**
     * @return list<ProfileRef>
     */
    public function detect(): array
    {
        if (! (bool) config('context-logging.profiling.correlate', true)) {
            return [];
        }

        $refs = [];

        foreach ($this->resolveAdapters() as $adapter) {
            try {
                $ref = $adapter->detect();
            } catch (\Throwable) {
                continue;
            }

            if ($ref !== null && $ref->enabled) {
                $refs[] = $ref;
            }
        }

        return $refs;
    }

    /**
     * @return list<ProfilerAdapter>
     */
    private function resolveAdapters(): array
    {
        if ($this->adapters !== null) {
            return $this->adapters;
        }

        $names = config('context-logging.profiling.adapters', ['spx', 'xdebug', 'blackfire']);
        if (! is_array($names)) {
            $names = ['spx', 'xdebug', 'blackfire'];
        }

        $map = [
            'spx' => SpxAdapter::class,
            'xdebug' => XdebugAdapter::class,
            'blackfire' => BlackfireAdapter::class,
            'tideways_xhprof' => TidewaysXhprofAdapter::class,
            'excimer' => ExcimerAdapter::class,
            'datadog' => DatadogAdapter::class,
        ];

        $adapters = [];
        foreach ($names as $name) {
            $key = strtolower((string) $name);
            if (! isset($map[$key])) {
                continue;
            }
            $adapters[] = new $map[$key]();
        }

        return $adapters;
    }
}
