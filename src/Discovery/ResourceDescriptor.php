<?php

declare(strict_types=1);

namespace haddowg\JsonApiLaravel\Discovery;

use haddowg\JsonApiLaravel\Operation\Operation;

/**
 * A plain, cacheable snapshot of everything the route registrar, the server
 * assembly and the authorizer need to know about one discovered JSON:API resource,
 * resolved WITHOUT instantiating the resource: its class-string, its JSON:API `type`,
 * its URI path segment (`uriType`), the server(s) it is exposed on, its exposed
 * operation allow-list (as plain {@see Operation} case-value strings), and its
 * authorization overrides (an optional dedicated policy class + per-operation ability
 * renames/disables).
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
     * @param class-string|null                                        $policy     a dedicated API policy class invoked directly (null = the model's Gate-registered policy, or inert)
     * @param array<string, string|false>                              $abilities  per-operation ability override keyed by {@see Operation} case value (string renames the ability, `false` disables the check)
     */
    public function __construct(
        public string $class,
        public string $type,
        public string $uriType,
        public array $servers,
        public array $operations,
        public ?string $policy = null,
        public array $abilities = [],
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
     * @return array{class: class-string<\haddowg\JsonApi\Resource\AbstractResource>, type: string, uriType: string, servers: list<string>, operations: list<string>, policy: class-string|null, abilities: array<string, string|false>}
     */
    public function toArray(): array
    {
        return [
            'class' => $this->class,
            'type' => $this->type,
            'uriType' => $this->uriType,
            'servers' => $this->servers,
            'operations' => $this->operations,
            'policy' => $this->policy,
            'abilities' => $this->abilities,
        ];
    }

    /**
     * A legacy snapshot missing the authorization keys degrades gracefully to the
     * inert default (no dedicated policy, no ability overrides).
     *
     * @param array{class: class-string<\haddowg\JsonApi\Resource\AbstractResource>, type: string, uriType: string, servers: list<string>, operations: list<string>, policy?: class-string|null, abilities?: array<string, string|false>} $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            $data['class'],
            $data['type'],
            $data['uriType'],
            $data['servers'],
            $data['operations'],
            $data['policy'] ?? null,
            $data['abilities'] ?? [],
        );
    }
}
