<?php

declare(strict_types=1);

namespace Michael4d45\ContextLogging\Profiling\Adapters;

use Michael4d45\ContextLogging\Profiling\Contracts\ProfilerAdapter;
use Michael4d45\ContextLogging\Profiling\ProfileRef;
use Michael4d45\ContextLogging\Profiling\SpxLifecycle;

/**
 * Detects php-spx when loaded and SPX_ENABLED / auto_enable.
 *
 * Prefers the report key from spx_profiler_stop() (called at emit time) so the
 * chip deep-links to /report.html&key=…. Falls back to a same-PID data-dir
 * scan, then to the SPX control panel root.
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

        if (! SpxLifecycle::isEnabled()) {
            return null;
        }

        $profileId = SpxLifecycle::stopAndCaptureKey()
            ?? SpxLifecycle::lastReportKey()
            ?? $this->newestReportKeyForPid();

        $uiBase = (string) config('context-logging.profiling.spx.ui_base_url', '');
        $httpKey = (string) config('context-logging.profiling.spx.http_key', 'dev');
        $url = null;

        if ($uiBase !== '') {
            $base = rtrim($uiBase, '/');
            $keyQs = $httpKey !== '' ? 'SPX_KEY='.rawurlencode($httpKey).'&' : '';
            if ($profileId !== null && $profileId !== '') {
                $url = $base.'/?'.$keyQs.'SPX_UI_URI=/report.html&key='.rawurlencode($profileId);
            } else {
                $url = $base.'/?'.$keyQs.'SPX_UI_URI=/';
            }
        }

        return new ProfileRef(
            vendor: 'spx',
            enabled: true,
            profileId: $profileId,
            path: null,
            url: $url,
            meta: [
                'report' => getenv('SPX_REPORT') ?: ($_COOKIE['SPX_REPORT'] ?? $_GET['SPX_REPORT'] ?? 'full'),
            ],
        );
    }

    private function newestReportKeyForPid(): ?string
    {
        $dir = ini_get('spx.data_dir') ?: '/tmp/spx';
        if (! is_dir($dir)) {
            return null;
        }

        $pid = (string) getmypid();
        $newest = null;
        $newestMtime = 0;

        foreach (glob(rtrim($dir, '/').'/spx-full-*.json') ?: [] as $file) {
            $base = pathinfo($file, PATHINFO_FILENAME);
            if (! preg_match('/-'.preg_quote($pid, '/').'-/', $base)) {
                continue;
            }

            $mtime = @filemtime($file) ?: 0;
            if ($mtime >= $newestMtime) {
                $newestMtime = $mtime;
                $newest = $base;
            }
        }

        if ($newest === null || (time() - $newestMtime) > 2) {
            return null;
        }

        return $newest;
    }
}
