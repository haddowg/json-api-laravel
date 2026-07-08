<?php

declare(strict_types=1);

namespace haddowg\JsonApiLaravel\Discovery;

use haddowg\JsonApiLaravel\Operation\Operation;

/**
 * A plain, cacheable snapshot of everything the route registrar, the server
 * assembly and the authorizer need to know about one discovered JSON:API resource,
 * resolved WITHOUT instantiating the resource: its class-string, its JSON:API `type`,
 * its URI path segment (`uriType`), the server(s) it is exposed on, its exposed
 * operation allow-list (as plain {@see Operation} case-value strings), its
 * authorization overrides (an optional dedicated policy class + per-operation ability
 * renames/disables), its optional serializer/hydrator override class-strings
 * (ADR 0015 — each container-constructed on first use, like the resource itself), and
 * its optional declared Eloquent model class-string (ADR 0019 — the `model:` tier of
 * the reference Eloquent layer's `type → model` resolution).
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
     * @param array<string, string|bool>                              $abilities  per-operation ability override keyed by {@see Operation} case value (string renames the ability, `false` disables the check)
     * @param array{cache?: array<string, mixed>, cache_operations?: array<string, array<string, mixed>>, deprecation?: array<string, mixed>} $headers the declarative response-header config (cache + deprecation/sunset) projected from the `#[AsJsonApiResource]` attribute, as scalars so it round-trips through the discovery cache
     * @param list<string>                                              $tags       the explicit OpenAPI tag names the type's operations are grouped under (empty = the humanized-type default)
     * @param class-string<\haddowg\JsonApi\Serializer\SerializerInterface>|null $serializer the custom serializer this type renders through (null = the resource's field inventory)
     * @param class-string<\haddowg\JsonApi\Hydrator\HydratorInterface>|null     $hydrator   the custom hydrator this type writes through (null = the resource's field inventory)
     * @param class-string<\Illuminate\Database\Eloquent\Model>|null             $model      the Eloquent model this type declares for the reference Eloquent layer (null = resolve by convention, ADR 0019)
     * @param array<string, list<array{status: int, jobType: string|null}>>      $responses  the per-operation OpenAPI success-response overrides, keyed by {@see Operation} case value → an ordered list of `{status, jobType}` scalar pairs (empty = each operation's default); kept as scalars so it round-trips through the discovery cache
     * @param array{restore: bool, forceDelete: bool, restoreAbility: string, forceAbility: string, restorePath: string, forcePath: string}|null $softDeletes the resolved {@see \haddowg\JsonApiLaravel\Attribute\SoftDeletes} config as scalars (null = not soft-deletable); the scanner reads it to synthesize the restore/force-delete actions
     */
    public function __construct(
        public string $class,
        public string $type,
        public string $uriType,
        public array $servers,
        public array $operations,
        public ?string $policy = null,
        public array $abilities = [],
        public array $headers = [],
        public array $tags = [],
        public ?string $serializer = null,
        public ?string $hydrator = null,
        public ?string $model = null,
        public array $responses = [],
        public ?array $softDeletes = null,
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
     * @return array{class: class-string<\haddowg\JsonApi\Resource\AbstractResource>, type: string, uriType: string, servers: list<string>, operations: list<string>, policy: class-string|null, abilities: array<string, string|bool>, headers: array{cache?: array<string, mixed>, cache_operations?: array<string, array<string, mixed>>, deprecation?: array<string, mixed>}, tags: list<string>, serializer: class-string<\haddowg\JsonApi\Serializer\SerializerInterface>|null, hydrator: class-string<\haddowg\JsonApi\Hydrator\HydratorInterface>|null, model: class-string<\Illuminate\Database\Eloquent\Model>|null, responses: array<string, list<array{status: int, jobType: string|null}>>, softDeletes: array{restore: bool, forceDelete: bool, restoreAbility: string, forceAbility: string, restorePath: string, forcePath: string}|null}
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
            'headers' => $this->headers,
            'tags' => $this->tags,
            'serializer' => $this->serializer,
            'hydrator' => $this->hydrator,
            'model' => $this->model,
            'responses' => $this->responses,
            'softDeletes' => $this->softDeletes,
        ];
    }

    /**
     * A legacy snapshot missing the authorization / header / tag / override / model keys
     * degrades gracefully to the inert default (no dedicated policy, no ability overrides,
     * no response headers, no explicit tags, no serializer/hydrator override, no declared
     * model — the convention tier still applies at map-resolution time).
     *
     * @param array{class: class-string<\haddowg\JsonApi\Resource\AbstractResource>, type: string, uriType: string, servers: list<string>, operations: list<string>, policy?: class-string|null, abilities?: array<string, string|bool>, headers?: array{cache?: array<string, mixed>, cache_operations?: array<string, array<string, mixed>>, deprecation?: array<string, mixed>}, tags?: list<string>, serializer?: class-string<\haddowg\JsonApi\Serializer\SerializerInterface>|null, hydrator?: class-string<\haddowg\JsonApi\Hydrator\HydratorInterface>|null, model?: class-string<\Illuminate\Database\Eloquent\Model>|null, responses?: array<string, list<array{status: int, jobType: string|null}>>, softDeletes?: array{restore: bool, forceDelete: bool, restoreAbility: string, forceAbility: string, restorePath: string, forcePath: string}|null} $data
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
            $data['headers'] ?? [],
            $data['tags'] ?? [],
            $data['serializer'] ?? null,
            $data['hydrator'] ?? null,
            $data['model'] ?? null,
            $data['responses'] ?? [],
            $data['softDeletes'] ?? null,
        );
    }
}
