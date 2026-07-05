<?php

declare(strict_types=1);

namespace haddowg\JsonApiLaravel\Discovery;

/**
 * A plain, cacheable snapshot of a standalone-hydrator capability (the write twin of
 * {@see SerializerDescriptor}, the Laravel twin of the bundle's ADR 0024): a
 * {@see \haddowg\JsonApi\Hydrator\HydratorInterface} registered for a JSON:API `type`
 * via `#[\haddowg\JsonApiLaravel\Attribute\AsJsonApiHydrator]` with **no**
 * {@see \haddowg\JsonApi\Resource\AbstractResource}.
 *
 * It carries what the server assembly and the route registrar's write-capability guard
 * need, resolved WITHOUT instantiating the hydrator: its class-string, its JSON:API
 * `type`, and the server(s) it is exposed on. A hydrator declares no operation
 * allow-list of its own — endpoints are opened by the paired serializer's allow-list
 * (or a resource's); the hydrator's presence is what makes the type's `Create`/`Update`
 * legal.
 *
 * Only scalars + lists, so a set of descriptors round-trips through {@see toArray()} /
 * {@see fromArray()} to the `var_export`-able discovery cache (keeping route
 * registration a pure function of the cached snapshot, so `route:cache` is safe).
 */
final readonly class HydratorDescriptor
{
    /**
     * @param class-string<\haddowg\JsonApi\Hydrator\HydratorInterface> $class   the hydrator class-string (constructed lazily, via the container, on first use)
     * @param string                                                    $type    the JSON:API resource type (`#[AsJsonApiHydrator(type:)]`)
     * @param list<string>                                              $servers the server name(s) exposing this type (at least `['default']`)
     */
    public function __construct(
        public string $class,
        public string $type,
        public array $servers,
    ) {}

    /**
     * Whether this standalone hydrator is exposed on the named server.
     */
    public function exposedOn(string $server): bool
    {
        return \in_array($server, $this->servers, true);
    }

    /**
     * @return array{class: class-string<\haddowg\JsonApi\Hydrator\HydratorInterface>, type: string, servers: list<string>}
     */
    public function toArray(): array
    {
        return [
            'class' => $this->class,
            'type' => $this->type,
            'servers' => $this->servers,
        ];
    }

    /**
     * @param array{class: class-string<\haddowg\JsonApi\Hydrator\HydratorInterface>, type: string, servers: list<string>} $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            $data['class'],
            $data['type'],
            $data['servers'],
        );
    }
}
