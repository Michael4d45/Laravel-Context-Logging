<?php

declare(strict_types=1);

namespace Michael4d45\ContextLogging\Profiling;

/**
 * Join keys for an active (or detected) native profiler run.
 *
 * Flamegraph / call-graph payloads stay in the profiler UI; this only
 * correlates the wide event to that external profile.
 */
final class ProfileRef
{
    /**
     * @param  array<string, mixed>  $meta
     */
    public function __construct(
        public readonly string $vendor,
        public readonly bool $enabled,
        public readonly ?string $profileId = null,
        public readonly ?string $path = null,
        public readonly ?string $url = null,
        public readonly array $meta = [],
    ) {}

    /**
     * @return array{
     *     vendor: string,
     *     enabled: bool,
     *     profile_id: string|null,
     *     path: string|null,
     *     url: string|null,
     *     meta: array<string, mixed>
     * }
     */
    public function toArray(): array
    {
        return [
            'vendor' => $this->vendor,
            'enabled' => $this->enabled,
            'profile_id' => $this->profileId,
            'path' => $this->path,
            'url' => $this->url,
            'meta' => $this->meta,
        ];
    }
}
