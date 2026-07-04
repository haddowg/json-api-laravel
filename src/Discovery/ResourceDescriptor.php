<?php

declare(strict_types=1);

namespace haddowg\JsonApiLaravel\Discovery;

use haddowg\JsonApiLaravel\Operation\Operation;

/**
 * A plain, cacheable snapshot of everything the route registrar and the server
 * assembly need to know about one discovered JSON:API resource, resolved WITHOUT
 * instantiating the resource: its class-string, its JSON:API `type`, its URI path
 * segment (`uriType`), the server(s) it is exposed on, and its exposed operation
 * allow-list (as plain {@see Operation} case-value strings).
 *
 * It carries only scalars + lists, so a set of descriptors round-trips through
 * {@see toArray()} / {@see fromArray()} to a `var_export`-able discovery cache file
 * (keeping route registration a pure function of the cached snapshot, so
 * `route:cache` is safe).
 */
final readonly class ResourceDescriptor
{
    /**
     * @param class-string<\haddowg\JsonApi\Resource\AbstractResource> $class      the resource class-string (constructed lazily, via the container, on first use)
     * @param string                                                   $type       the JSON:API resource type (`::$type` or the attribute override)
     * @param string                                                   $uriType    the URI path segment (`::$uriType` when set, else `$type`)
     * @param list<string>                                             $servers    the server name(s) exposing this type (at least `['default']`)
     * @param list<string>                                             $operations the exposed operations as {@see Operation} case-value strings
     */
    public function __construct(
        public string $class,
        public string $type,
        public string $uriType,
        public array $servers,
        public array $operations,
    ) {}

    /**
     * Whether this resource is exposed on the named server.
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
     * @return array{class: class-string<\haddowg\JsonApi\Resource\AbstractResource>, type: string, uriType: string, servers: list<string>, operations: list<string>}
     */
    public function toArray(): array
    {
        return [
            'class' => $this->class,
            'type' => $this->type,
            'uriType' => $this->uriType,
            'servers' => $this->servers,
            'operations' => $this->operations,
        ];
    }

    /**
     * @param array{class: class-string<\haddowg\JsonApi\Resource\AbstractResource>, type: string, uriType: string, servers: list<string>, operations: list<string>} $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            $data['class'],
            $data['type'],
            $data['uriType'],
            $data['servers'],
            $data['operations'],
        );
    }
}
