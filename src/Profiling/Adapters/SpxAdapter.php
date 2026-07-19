<?php

declare(strict_types=1);

namespace Michael4d45\ContextLogging\Profiling\Adapters;

use Michael4d45\ContextLogging\Profiling\Contracts\ProfilerAdapter;
use Michael4d45\ContextLogging\Profiling\ProfileRef;

/**
 * Detects php-spx when the extension is loaded and profiling is enabled
 * via SPX_ENABLED (env/cookie). Does not start/stop the profiler.
 *
 * Report keys are only available when the app (or SPX auto mode) produces
 * them; without a key we still mark enabled and optionally link the UI root.
 */
final class SpxAdapter implements ProfilerAdapter
{
    public function name(): string
    {
        return 'spx';
    }

    public function detect(): ?ProfileRef
    {
        if (! extension_loaded('spx')) {
            return null;
        }

        if (! $this->isEnabled()) {
            return null;
        }

        $profileId = $this->resolveReportKey();
        $uiBase = (string) config('context-logging.profiling.spx.ui_base_url', '');
        $url = null;

        if ($uiBase !== '') {
            $base = rtrim($uiBase, '/');
            if ($profileId !== null && $profileId !== '') {
                $url = $base.'/?SPX_UI_URI=/report.html&key='.rawurlencode($profileId);
            } else {
                $url = $base.'/?SPX_UI_URI=/';
            }
        }

        return new ProfileRef(
            vendor: 'spx',
            enabled: true,
            profileId: $profileId,
            path: null,
            url: $url,
            meta: [
                'report' => getenv('SPX_REPORT') ?: ($_COOKIE['SPX_REPORT'] ?? null),
            ],
        );
    }

    private function isEnabled(): bool
    {
        $env = getenv('SPX_ENABLED');
        if ($env !== false && $env !== '' && $env !== '0') {
            return true;
        }

        $cookie = $_COOKIE['SPX_ENABLED'] ?? null;
        if ($cookie !== null && $cookie !== '' && $cookie !== '0') {
            return true;
        }

        return false;
    }

    private function resolveReportKey(): ?string
    {
        // Manual stop returns the full-report key when SPX_REPORT=full.
        // Under auto-start we must not call stop(); leave profile_id null.
        $autoStart = getenv('SPX_AUTO_START');
        if ($autoStart === false) {
            $autoStart = $_COOKIE['SPX_AUTO_START'] ?? '1';
        }

        if ((string) $autoStart !== '0') {
            return null;
        }

        if (! function_exists('spx_profiler_stop')) {
            return null;
        }

        try {
            $key = spx_profiler_stop();
        } catch (\Throwable) {
            return null;
        }

        return is_string($key) && $key !== '' ? $key : null;
    }
}
