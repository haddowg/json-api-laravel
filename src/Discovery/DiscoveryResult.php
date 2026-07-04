<?php

declare(strict_types=1);

namespace haddowg\JsonApiLaravel\Discovery;

/**
 * The outcome of a discovery scan: the resource descriptors (for routing + server
 * assembly) and the container-constructible SPI implementations found under the
 * scanned paths. The provider/persister class-strings are resolved (and instanceof-
 * guarded) by the service provider when it builds the registries, so they are carried
 * here as plain class-strings.
 */
final readonly class DiscoveryResult
{
    /**
     * @param list<ResourceDescriptor> $resources  the discovered resources' descriptors
     * @param list<class-string>       $providers  the discovered data-provider class-strings
     * @param list<class-string>       $persisters the discovered data-persister class-strings
     */
    public function __construct(
        public array $resources,
        public array $providers,
        public array $persisters,
    ) {}
}
