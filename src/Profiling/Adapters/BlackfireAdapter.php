<?php

declare(strict_types=1);

namespace Michael4d45\ContextLogging\Profiling\Adapters;

use Michael4d45\ContextLogging\Profiling\Contracts\ProfilerAdapter;
use Michael4d45\ContextLogging\Profiling\ProfileRef;

/**
 * Detects an active Blackfire probe and attaches profile UUID / query metadata.
 */
final class BlackfireAdapter implements ProfilerAdapter
{
    public function name(): string
    {
        return 'blackfire';
    }

    public function detect(): ?ProfileRef
    {
        if (! extension_loaded('blackfire')) {
            return null;
        }

        $uuid = null;
        $meta = [];

        if (class_exists(\BlackfireProbe::class, false)) {
            try {
                $probe = \BlackfireProbe::getMainInstance();
                if (is_object($probe)) {
                    if (method_exists($probe, 'getProfileUuid')) {
                        $candidate = $probe->getProfileUuid();
                        if (is_string($candidate) && $candidate !== '') {
                            $uuid = $candidate;
                        }
                    }
                    if (method_exists($probe, 'isEnabled') && ! $probe->isEnabled() && $uuid === null) {
                        return null;
                    }
                    if (method_exists($probe, 'getResponseLine')) {
                        $line = $probe->getResponseLine();
                        if (is_string($line) && $line !== '') {
                            $meta['response_line'] = $line;
                        }
                    }
                }
            } catch (\Throwable) {
                // Ignore probe API failures.
            }
        }

        // Cookie / query used by Blackfire browser extension / CLI trigger.
        $query = $_COOKIE['X-Blackfire-Query']
            ?? $_SERVER['HTTP_X_BLACKFIRE_QUERY']
            ?? getenv('BLACKFIRE_QUERY')
            ?: null;

        if ($uuid === null && (is_string($query) && $query !== '')) {
            $meta['query_present'] = true;
        } elseif ($uuid === null && $query === null) {
            return null;
        }

        $url = $uuid !== null
            ? 'https://blackfire.io/profiles/'.$uuid
            : null;

        return new ProfileRef(
            vendor: 'blackfire',
            enabled: true,
            profileId: $uuid,
            path: null,
            url: $url,
            meta: $meta,
        );
    }
}
