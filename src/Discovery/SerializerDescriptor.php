<?php

declare(strict_types=1);

namespace haddowg\JsonApiLaravel\Discovery;

use haddowg\JsonApiLaravel\Operation\Operation;

/**
 * A plain, cacheable snapshot of a standalone-serializer capability (the Laravel twin of
 * the bundle's ADR 0024): a {@see \haddowg\JsonApi\Serializer\SerializerInterface}
 * registered for a JSON:API `type` via `#[\haddowg\JsonApiLaravel\Attribute\AsJsonApiSerializer]`
 * with **no** {@see \haddowg\JsonApi\Resource\AbstractResource}.
 *
 * It carries what the route registrar, the server assembly and the OpenAPI metadata
 * source need, resolved WITHOUT instantiating the serializer: its class-string, its
 * JSON:API `type`, its URI path segment (`uriType`, defaulting to the type — a
 * standalone serializer declares its runtime segment through
 * {@see \haddowg\JsonApi\Serializer\UriTypeAwareInterface}, but the route/descriptor
 * segment is the type, matching the bundle), the server(s) it is exposed on, its exposed
 * operation allow-list (**empty by default** — serialize-only, no endpoints), and its
 * explicit OpenAPI tag refs.
 *
 * Only scalars + lists, so a set of descriptors round-trips through {@see toArray()} /
 * {@see fromArray()} to the `var_export`-able discovery cache (keeping route registration
 * a pure function of the cached snapshot, so `route:cache` is safe).
 */
final readonly class SerializerDescriptor
{
    /**
     * @param class-string<\haddowg\JsonApi\Serializer\SerializerInterface> $class      the serializer class-string (constructed lazily, via the container, on first use)
     * @param string                                                        $type       the JSON:API resource type (`#[AsJsonApiSerializer(type:)]`)
     * @param string                                                        $uriType    the URI path segment (defaults to `$type`)
     * @param list<string>                                                  $servers    the server name(s) exposing this type (at least `['default']`)
     * @param list<string>                                                  $operations the exposed operations as {@see Operation} case-value strings (empty = serialize-only)
     * @param list<string>                                                  $tags       the explicit OpenAPI tag names the type's operations are grouped under (empty = the humanized-type default)
     */
    public function __construct(
        public string $class,
        public string $type,
        public string $uriType,
        public array $servers,
        public array $operations,
        public array $tags = [],
    ) {}

    /**
     * Whether this standalone type is exposed on the named server.
     */
    public function exposedOn(string $server): bool
    {
        return \in_array($server, $this->servers, true);
    }

    /**
     * Whether the named operation ({@see Operation} case value) is exposed.
     */
    public function exposes(Operation $operation): bool
    {
        return \in_array($operation->value, $this->operations, true);
    }

    /**
     * @return array{class: class-string<\haddowg\JsonApi\Serializer\SerializerInterface>, type: string, uriType: string, servers: list<string>, operations: list<string>, tags: list<string>}
     */
    public function toArray(): array
    {
        return [
            'class' => $this->class,
            'type' => $this->type,
            'uriType' => $this->uriType,
            'servers' => $this->servers,
            'operations' => $this->operations,
            'tags' => $this->tags,
        ];
    }

    /**
     * @param array{class: class-string<\haddowg\JsonApi\Serializer\SerializerInterface>, type: string, uriType: string, servers: list<string>, operations: list<string>, tags?: list<string>} $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            $data['class'],
            $data['type'],
            $data['uriType'],
            $data['servers'],
            $data['operations'],
            $data['tags'] ?? [],
        );
    }
}
