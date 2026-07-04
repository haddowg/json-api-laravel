<?php

declare(strict_types=1);

namespace haddowg\JsonApiLaravel\Discovery;

/**
 * The memoized entry point to discovery: it resolves the discovered
 * {@see ResourceDescriptor}s and the container-constructible SPI class-strings
 * (data providers + persisters) once — from a pre-built snapshot cache file when one
 * is configured and present, otherwise by scanning the configured paths — and hands
 * them to the route registrar, the server assembly, and the provider/persister
 * registries.
 *
 * Because every consumer reads the SAME memoized result, and because loading a cached
 * snapshot bypasses the filesystem walk entirely, route registration stays a pure
 * function of the descriptors — so `route:cache` is safe. The snapshot carries the
 * scanned SPI class-strings alongside the resource descriptors (all plain,
 * `var_export`-able strings), so a cached configuration registers exactly the same
 * providers/persisters a live scan would — the cache is a faithful drop-in for
 * scanning, not a resources-only subset. The optimize pipeline that WRITES the
 * snapshot is wired in a later phase; for now a null cache path simply scans live on
 * first use (which, in a freshly-booted app, is once).
 */
final class Discovery
{
    /**
     * @var list<ResourceDescriptor>|null
     */
    private ?array $resources = null;

    /**
     * @var list<class-string>|null
     */
    private ?array $providers = null;

    /**
     * @var list<class-string>|null
     */
    private ?array $persisters = null;

    /**
     * @var list<class-string>|null
     */
    private ?array $translators = null;

    /**
     * @param list<string>       $paths           the directories to scan
     * @param list<class-string> $explicitClasses classes registered via `JsonApi::register()`
     * @param string|null        $cachePath       an optional pre-built snapshot file (resources + provider/persister class-strings); loaded instead of scanning when present
     */
    public function __construct(
        private readonly DiscoveryScanner $scanner,
        private readonly array $paths,
        private readonly array $explicitClasses = [],
        private readonly ?string $cachePath = null,
    ) {}

    /**
     * The discovered resource descriptors.
     *
     * @return list<ResourceDescriptor>
     */
    public function resources(): array
    {
        return $this->resources ??= $this->resolve()['resources'];
    }

    /**
     * The discovered resource descriptors exposed on the named server.
     *
     * @return list<ResourceDescriptor>
     */
    public function resourcesFor(string $server): array
    {
        return \array_values(\array_filter(
            $this->resources(),
            static fn(ResourceDescriptor $descriptor): bool => $descriptor->exposedOn($server),
        ));
    }

    /**
     * The discovered, container-constructible data-provider class-strings.
     *
     * @return list<class-string>
     */
    public function providers(): array
    {
        if ($this->providers === null) {
            $this->resolve();
        }

        return $this->providers ?? [];
    }

    /**
     * The discovered, container-constructible data-persister class-strings.
     *
     * @return list<class-string>
     */
    public function persisters(): array
    {
        if ($this->persisters === null) {
            $this->resolve();
        }

        return $this->persisters ?? [];
    }

    /**
     * The discovered, container-constructible constraint-translator class-strings — the
     * class-keyed validation extension point (PLAN decision 6).
     *
     * @return list<class-string>
     */
    public function translators(): array
    {
        if ($this->translators === null) {
            $this->resolve();
        }

        return $this->translators ?? [];
    }

    /**
     * @return array{resources: list<ResourceDescriptor>, providers: list<class-string>, persisters: list<class-string>, translators: list<class-string>}
     */
    private function resolve(): array
    {
        $snapshot = $this->loadSnapshot();
        if ($snapshot !== null) {
            $this->resources = $snapshot['resources'];
            $this->providers = $snapshot['providers'];
            $this->persisters = $snapshot['persisters'];
            $this->translators = $snapshot['translators'];

            return $snapshot;
        }

        $result = $this->scanner->scan($this->paths, $this->explicitClasses);
        $this->resources = $result->resources;
        $this->providers = $result->providers;
        $this->persisters = $result->persisters;
        $this->translators = $result->translators;

        return [
            'resources' => $result->resources,
            'providers' => $result->providers,
            'persisters' => $result->persisters,
            'translators' => $result->translators,
        ];
    }

    /**
     * Loads the discovery snapshot from the configured cache file, or `null` when no
     * cache is configured or the file is absent/malformed (fall back to a live scan).
     *
     * The snapshot is a keyed array `{resources, providers, persisters}`: the resources
     * are `ResourceDescriptor` array forms, the providers/persisters are plain
     * container-resolvable class-strings — exactly what a live scan yields, so a cached
     * configuration is behaviourally identical to a scanned one. Missing keys degrade
     * gracefully to empty lists (a resources-only file still loads its resources).
     *
     * @return array{resources: list<ResourceDescriptor>, providers: list<class-string>, persisters: list<class-string>, translators: list<class-string>}|null
     */
    private function loadSnapshot(): ?array
    {
        if ($this->cachePath === null || !\is_file($this->cachePath)) {
            return null;
        }

        /** @var mixed $data */
        $data = require $this->cachePath;
        if (!\is_array($data)) {
            return null;
        }

        return [
            'resources' => $this->readResources($data['resources'] ?? []),
            'providers' => $this->readClassStrings($data['providers'] ?? []),
            'persisters' => $this->readClassStrings($data['persisters'] ?? []),
            'translators' => $this->readClassStrings($data['translators'] ?? []),
        ];
    }

    /**
     * Rebuilds the resource descriptors from their snapshot array forms, skipping any
     * malformed entry.
     *
     * @return list<ResourceDescriptor>
     */
    private function readResources(mixed $entries): array
    {
        if (!\is_array($entries)) {
            return [];
        }

        $resources = [];
        foreach ($entries as $entry) {
            if (\is_array($entry)) {
                /** @var array{class: class-string<\haddowg\JsonApi\Resource\AbstractResource>, type: string, uriType: string, servers: list<string>, operations: list<string>} $entry */
                $resources[] = ResourceDescriptor::fromArray($entry);
            }
        }

        return $resources;
    }

    /**
     * Filters a snapshot member down to a clean list of class-strings.
     *
     * @return list<class-string>
     */
    private function readClassStrings(mixed $values): array
    {
        if (!\is_array($values)) {
            return [];
        }

        $classes = [];
        foreach ($values as $value) {
            if (\is_string($value) && $value !== '') {
                /** @var class-string $value */
                $classes[] = $value;
            }
        }

        return $classes;
    }
}
