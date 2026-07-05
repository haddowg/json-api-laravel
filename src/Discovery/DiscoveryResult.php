<?php

declare(strict_types=1);

namespace haddowg\JsonApiLaravel\Discovery;

/**
 * The outcome of a discovery scan: the resource descriptors (for routing + server
 * assembly), the container-constructible SPI implementations, and the custom-action
 * descriptors found under the scanned paths. The provider/persister class-strings are
 * resolved (and instanceof-guarded) by the service provider when it builds the
 * registries, so they are carried here as plain class-strings.
 */
final readonly class DiscoveryResult
{
    /**
     * @param list<ResourceDescriptor>                           $resources   the discovered resources' descriptors
     * @param list<class-string>                                 $providers   the discovered data-provider class-strings
     * @param list<class-string>                                 $persisters  the discovered data-persister class-strings
     * @param list<class-string>                                 $translators the discovered constraint-translator class-strings
     * @param list<\haddowg\JsonApiLaravel\Action\ActionDescriptor> $actions     the discovered custom-action descriptors
     */
    public function __construct(
        public array $resources,
        public array $providers,
        public array $persisters,
        public array $translators = [],
        public array $actions = [],
    ) {}
}
