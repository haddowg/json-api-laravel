<?php

declare(strict_types=1);

namespace haddowg\JsonApiLaravel\OpenApi\Metadata;

use haddowg\JsonApi\OpenApi\Metadata\OperationType;
use haddowg\JsonApi\OpenApi\Metadata\PaginatorKind;
use haddowg\JsonApi\OpenApi\Metadata\ServerMetadataInterface;
use haddowg\JsonApi\OpenApi\Metadata\TypeMetadataInterface;
use haddowg\JsonApi\OpenApi\Tag;
use haddowg\JsonApi\Pagination\PaginatorInterface;
use haddowg\JsonApi\Resource\Field\Id;
use haddowg\JsonApi\Resource\Field\RelationInterface;
use haddowg\JsonApi\Server\Server;
use haddowg\JsonApiLaravel\Discovery\Discovery;
use haddowg\JsonApiLaravel\Discovery\ResourceDescriptor;
use haddowg\JsonApiLaravel\Discovery\SerializerDescriptor;
use haddowg\JsonApiLaravel\Operation\Operation;
use haddowg\JsonApiLaravel\Server\ServerRegistry;
use haddowg\JsonApiLaravel\Server\TypeMetadataResolver;

/**
 * The package's implementation of core's OpenAPI metadata contract (PLAN decision 11)
 * — it builds, for one server, the {@see ServerMetadataInterface} (with its
 * {@see TypeMetadataInterface} family) the core
 * {@see \haddowg\JsonApi\OpenApi\OpenApiProjector} projects into an OpenAPI document.
 *
 * It reads the live registry — the discovered {@see ResourceDescriptor}s (the
 * per-server type list with each type's uriType / operations / policy-ability
 * overrides), the {@see TypeMetadataResolver} (resource + relations), the resource's own
 * {@see Id} field (client-id policy + route pattern), an optional
 * {@see ActionMetadataProviderInterface} (custom actions, bound by the sibling action
 * subsystem) — and folds in the config-shaped {@see ServerDocumentConfig} (info /
 * advertised servers / tag definitions / security schemes, from `jsonapi.openapi.*`).
 *
 * This is the byte-compat seam (PLAN decision 11): it must feed the projector the SAME
 * facts the Symfony bundle's `MetadataSource` does, so `json-api-ts` codegen consumes
 * either backend unchanged. Field decisions mirror the bundle exactly:
 *
 *  - **`sorts:`** uses `allSorts()`, not `sorts()` — the field-`->sortable()` union plus
 *    explicit overrides (missing this drops the `sort` param for the common case).
 *  - **`securedOperations`/`publicOperations`** are projected from the type's
 *    authorization config. An operation is **secured** (the projector emits the document
 *    security requirement + a `401`) when its {@see ResourceDescriptor::$abilities} entry is
 *    a string (a declared/renamed Gate ability) or `true` (documentation-only, external
 *    enforcement), OR the type declares a dedicated `policy:` class (every exposed operation,
 *    minus any explicitly disabled with `false`) — so a policy-secured type does not project
 *    as unsecured. An entry of `false` (the check disabled) is **public** (an operation-level
 *    `security: []`); an unsecured, policy-less operation inherits the document-level default.
 *    This is the Laravel translation of the bundle's Symfony security-expression projection:
 *    the *document* carries the same security/401 shape, projected from policy/ability config
 *    instead of expressions (PLAN decision 7 — OpenAPI security stays config/attribute-declared;
 *    a Gate/policy registered without an attribute declaration is deliberately not projected).
 *  - **`idPattern`** is the un-anchored `{id}` regex the resource's {@see Id} declares;
 *    core's `OperationProjector` anchors it as `^(?:…)$`.
 *  - **`paginatorKind`** is resolved off `resource->pagination(serverDefault)`; a to-one
 *    relation is always {@see PaginatorKind::None}.
 *  - **`tags`** are the explicit `#[AsJsonApiResource(tags: …)]` refs, else the
 *    humanized-type default ({@see TagNameResolver}); a custom action with no tags of its
 *    own inherits the mount type's explicit tags (then the default), exactly as the bundle
 *    resolves them.
 */
final class MetadataSource
{
    /**
     * @param array<string, ServerDocumentConfig> $configByServer the per-server document config, keyed by server name; a server with no entry uses defaults
     * @param list<string>                         $serverNames    the declared server names (`default` first), the same per-server type source `forServer()` reads
     * @param bool                                 $atomicEnabled  whether the Atomic Operations extension is enabled (`jsonapi.atomic_operations.enabled`); when true every server's document gains the atomic endpoint
     * @param string                               $atomicPath     the path the per-server atomic endpoint is served at (`jsonapi.atomic_operations.path`)
     */
    public function __construct(
        private readonly ServerRegistry $servers,
        private readonly Discovery $discovery,
        private readonly TypeMetadataResolver $types,
        private readonly PaginatorKindResolver $paginatorKinds,
        private readonly TagNameResolver $tagNames,
        private readonly IncludePathResolver $includePaths,
        private readonly array $serverNames = [ServerRegistry::DEFAULT_SERVER],
        private readonly array $configByServer = [],
        private readonly bool $atomicEnabled = false,
        private readonly string $atomicPath = '/operations',
        private readonly ?ActionMetadataProviderInterface $actions = null,
    ) {}

    /**
     * The complete OpenAPI metadata for `$serverName` (the implicit `default` when
     * null), ready for the core projector.
     */
    public function forServer(?string $serverName = null): ServerMetadataInterface
    {
        $serverName ??= ServerRegistry::DEFAULT_SERVER;
        $server = $this->servers->get($serverName);
        $config = $this->configByServer[$serverName] ?? new ServerDocumentConfig();

        $types = $this->typesFor($serverName, $server);

        return new ServerMetadata(
            title: $config->title ?? $this->defaultTitle($serverName),
            version: $config->version ?? '1.0.0',
            description: $config->description,
            contact: $config->contact,
            license: $config->license,
            servers: $config->servers !== [] ? $config->servers : $this->defaultServers($server),
            jsonApiVersion: $server->jsonApiVersion(),
            tags: $this->tagDefinitions($config, $types),
            securitySchemes: $config->securitySchemes,
            defaultSecurity: $config->defaultSecurity,
            externalDocs: $config->externalDocs,
            types: $types,
            atomicOperations: $this->atomicOperations(),
        );
    }

    /**
     * The **combined** OpenAPI metadata spanning every declared server in one document
     * (PLAN decision 11 / §7) — the source for `multi_server: combined`. It unions every
     * server's types (asserting non-colliding JSON:API types across servers, since one
     * document cannot carry two components for the same type), concatenates the
     * advertised base URIs, and dedupes the tag definitions and security schemes.
     *
     * The `info` block and the document-level default security come from the `default`
     * server's config — there is one combined document, so it carries one info block.
     *
     * @throws \LogicException when two servers declare the same JSON:API type
     */
    public function combined(): ServerMetadataInterface
    {
        $defaultConfig = $this->configByServer[ServerRegistry::DEFAULT_SERVER] ?? new ServerDocumentConfig();
        $defaultServer = $this->servers->get(ServerRegistry::DEFAULT_SERVER);

        $types = [];
        $typeNames = [];
        $advertised = [];
        $securitySchemes = [];
        foreach ($this->serverNames as $serverName) {
            $server = $this->servers->get($serverName);
            $config = $this->configByServer[$serverName] ?? new ServerDocumentConfig();

            foreach ($this->typesFor($serverName, $server) as $type) {
                if (\in_array($type->type(), $typeNames, true)) {
                    throw new \LogicException(\sprintf(
                        'Cannot build a combined OpenAPI document: the JSON:API type "%s" is declared on more than one server. Combined mode requires non-colliding types across servers.',
                        $type->type(),
                    ));
                }

                $types[] = $type;
                $typeNames[] = $type->type();
            }

            foreach (($config->servers !== [] ? $config->servers : $this->defaultServers($server)) as $advertisedServer) {
                $advertised[$advertisedServer->url] ??= $advertisedServer;
            }

            foreach ($config->securitySchemes as $name => $scheme) {
                $securitySchemes[$name] ??= $scheme;
            }
        }

        return new ServerMetadata(
            title: $defaultConfig->title ?? $this->defaultTitle(ServerRegistry::DEFAULT_SERVER),
            version: $defaultConfig->version ?? '1.0.0',
            description: $defaultConfig->description,
            contact: $defaultConfig->contact,
            license: $defaultConfig->license,
            servers: \array_values($advertised),
            jsonApiVersion: $defaultServer->jsonApiVersion(),
            tags: $this->tagDefinitions($defaultConfig, $types),
            securitySchemes: $securitySchemes,
            defaultSecurity: $defaultConfig->defaultSecurity,
            externalDocs: $defaultConfig->externalDocs,
            types: $types,
            atomicOperations: $this->atomicOperations(),
        );
    }

    /**
     * The Atomic Operations extension endpoint metadata, or `null` when the extension is
     * disabled. The extension is a single global flag but the endpoint exists per server,
     * so every server's document carries the same metadata when enabled. The atomic
     * operation carries no per-endpoint security of its own (empty `security()`), so
     * core's projector falls back to the document-level default.
     */
    private function atomicOperations(): ?AtomicOperationsMetadata
    {
        if (!$this->atomicEnabled) {
            return null;
        }

        return new AtomicOperationsMetadata($this->atomicPath);
    }

    /**
     * Builds the type metadata list for one server, in descriptor (discovery) order:
     * the resources first, then the standalone-serializer types (PLAN decision 3, bundle
     * ADR 0024) — the same resources-then-standalone ordering the bundle's descriptor map
     * carries, so the projected OpenAPI `paths` stay byte-compatible.
     *
     * @return list<TypeMetadataInterface>
     */
    private function typesFor(string $serverName, Server $server): array
    {
        $types = [];
        foreach ($this->discovery->resourcesFor($serverName) as $descriptor) {
            if ($descriptor->type === '') {
                continue;
            }

            $types[] = $this->buildType($server, $serverName, $descriptor);
        }

        foreach ($this->discovery->serializersFor($serverName) as $descriptor) {
            if ($descriptor->type === '') {
                continue;
            }

            $types[] = $this->buildSerializerType($server, $serverName, $descriptor);
        }

        return $types;
    }

    /**
     * Assembles a standalone-serializer type's metadata (no resource, no field inventory)
     * — the fieldless projection path (PLAN decision 3, bundle ADR 0024). `hasFields` is
     * `false`, so core's projector emits a permissive resource object with an inline
     * `attributes: {type: object}` (no `{Type}Attributes` `$ref`), byte-identical to the
     * bundle. It carries no authorization config of its own, so it declares no
     * secured/public operations and inherits the document-level default security; a
     * resource-less type has no client-id policy, id pattern, paginator, filter/sort
     * vocabulary or include tree. Custom actions mounted on the type (if any) still project.
     */
    private function buildSerializerType(Server $server, string $serverName, SerializerDescriptor $descriptor): TypeMetadata
    {
        $type = $descriptor->type;
        $operations = $this->operations($descriptor->operations);

        return new TypeMetadata(
            type: $type,
            uriType: $descriptor->uriType,
            hasFields: false,
            fields: [],
            relations: [],
            operations: $operations,
            securedOperations: [],
            publicOperations: [],
            allowsClientId: false,
            requiresClientId: false,
            idPattern: null,
            paginatorKind: PaginatorKind::None,
            countable: false,
            filters: [],
            sorts: [],
            actions: $this->actions?->forServerType($serverName, $type) ?? [],
            tags: $this->typeTags($descriptor->tags, $type),
            description: null,
            operationDescriptions: [],
            includablePaths: [],
        );
    }

    /**
     * Assembles one type's metadata from its descriptor + the live registry.
     */
    private function buildType(Server $server, string $serverName, ResourceDescriptor $descriptor): TypeMetadata
    {
        $type = $descriptor->type;
        $resource = $this->types->resourceFor($server, $type);
        $serverDefaultPaginator = $server->defaultPaginator();
        $idField = $this->idFieldFor($resource);

        $relations = [];
        foreach ($this->types->relationsFor($server, $type) as $relation) {
            $relations[] = $this->buildRelation($server, $relation, $serverDefaultPaginator);
        }

        $operations = $this->operations($descriptor->operations);

        $actions = $this->actions?->forServerType($serverName, $type) ?? [];

        $paginatorKind = $resource !== null
            ? $this->paginatorKinds->resolve($resource->pagination($serverDefaultPaginator))
            : PaginatorKind::None;

        return new TypeMetadata(
            type: $type,
            uriType: $descriptor->uriType,
            hasFields: $resource !== null,
            fields: $resource !== null ? \array_values($resource->fields()) : [],
            relations: $relations,
            operations: $operations,
            securedOperations: $this->securedOperations($descriptor, $operations),
            publicOperations: $this->publicOperations($descriptor, $operations),
            allowsClientId: $idField?->allowsClientId() ?? false,
            requiresClientId: $idField?->requiresClientId() ?? false,
            // The type's {id} route requirement (the un-anchored regex fragment a
            // uuid()/ulid()/numeric()/pattern()/matchAs() id declares), or null for an
            // unconstrained id. Core's OperationProjector anchors it as `^(?:<fragment>)$`.
            idPattern: $idField?->routePattern(),
            paginatorKind: $paginatorKind,
            countable: $resource?->isCountable() ?? false,
            filters: $resource !== null ? \array_values($resource->filters()) : [],
            // allSorts(), not sorts(): the runtime accepts the field-derived sortables
            // (every `->sortable()` field) UNION the explicit sorts() overrides, so the
            // document must enumerate the same full set.
            sorts: $resource !== null ? \array_values($resource->allSorts()) : [],
            actions: $actions,
            tags: $this->typeTags($descriptor->tags, $type),
            // The resource's own description method hooks (attribute-level overrides land
            // in a later slice); the projector supplies the generated default when null.
            description: $resource?->getDescription(),
            operationDescriptions: $this->operationDescriptions($resource),
            includablePaths: $resource !== null ? $this->includePaths->pathsFor($server, $type) : [],
        );
    }

    /**
     * The per-CRUD-operation OpenAPI description overrides, keyed by
     * {@see OperationType::value}, from the resource's
     * {@see \haddowg\JsonApi\Resource\AbstractResource::describeOperation()} method hook
     * (the projector emits the generated default when null).
     *
     * @return array<string, ?string>
     */
    private function operationDescriptions(?\haddowg\JsonApi\Resource\AbstractResource $resource): array
    {
        if ($resource === null) {
            return [];
        }

        $descriptions = [];
        foreach (OperationType::cases() as $operation) {
            $value = $resource->describeOperation($operation);
            if ($value !== null) {
                $descriptions[$operation->value] = $value;
            }
        }

        return $descriptions;
    }

    private function buildRelation(Server $server, RelationInterface $relation, ?PaginatorInterface $serverDefault): RelationMetadata
    {
        // Only a to-many relation has a related-collection to paginate; a to-one is
        // always PaginatorKind::None. For a to-many the resolved paginator rides core's
        // relation → related-resource → server-default fallback chain.
        $kind = $relation->isToMany()
            ? $this->paginatorKinds->resolve($relation->pagination($serverDefault))
            : PaginatorKind::None;

        return new RelationMetadata(
            $relation,
            $kind,
            $this->includePaths->relatedPathsFor($server, $relation),
        );
    }

    /**
     * Maps the descriptor's {@see Operation} value strings to core {@see OperationType}
     * (the two enums share backing values), dropping any unknown value and de-duplicating.
     *
     * @param list<string> $operations
     *
     * @return list<OperationType>
     */
    private function operations(array $operations): array
    {
        $mapped = [];
        foreach ($operations as $value) {
            $operation = Operation::tryFrom($value);
            if ($operation === null) {
                continue;
            }

            $type = OperationType::tryFrom($operation->value);
            if ($type !== null && !\in_array($type, $mapped, true)) {
                $mapped[] = $type;
            }
        }

        return $mapped;
    }

    /**
     * The subset of `$operations` the type secures — projected with the document security
     * requirement + a `401`, mirroring the bundle's expression-secured operations. An
     * operation is secured when it declares a Gate ability (`string`), is marked
     * documentation-only secured (`true`, external enforcement), OR the type declares a
     * dedicated `policy:` class (every exposed operation, minus any explicitly disabled with
     * `false`) — so a policy-secured type (the primary Phase-2 idiom) no longer projects as
     * unsecured.
     *
     * @param list<OperationType> $operations
     *
     * @return list<OperationType>
     */
    private function securedOperations(ResourceDescriptor $descriptor, array $operations): array
    {
        $policySecured = $descriptor->policy !== null;

        return $this->securityOperations(
            $descriptor,
            $operations,
            static fn(string|bool|null $value): bool => $policySecured
                ? $value !== false
                : (\is_string($value) || $value === true),
        );
    }

    /**
     * The operations the type declared **public** (the check disabled — `false` in the
     * ability map) — documented with an operation-level `security: []` and no 401,
     * overriding the document default.
     *
     * @param list<OperationType> $operations
     *
     * @return list<OperationType>
     */
    private function publicOperations(ResourceDescriptor $descriptor, array $operations): array
    {
        return $this->securityOperations($descriptor, $operations, static fn(string|bool|null $value): bool => $value === false);
    }

    /**
     * The exposed operations whose per-operation ability declaration matches `$matches`
     * — the projection of the descriptor's ability map onto {@see OperationType}. The
     * ability map is keyed by {@see Operation} case value, which equals the
     * {@see OperationType} value, so an operation maps directly.
     *
     * @param list<OperationType>              $operations
     * @param \Closure(string|bool|null): bool $matches
     *
     * @return list<OperationType>
     */
    private function securityOperations(ResourceDescriptor $descriptor, array $operations, \Closure $matches): array
    {
        $result = [];
        foreach ($operations as $operationType) {
            $value = $descriptor->abilities[$operationType->value] ?? null;
            if ($matches($value) && !\in_array($operationType, $result, true)) {
                $result[] = $operationType;
            }
        }

        return $result;
    }

    /**
     * The OpenAPI tag refs for a type: the explicit `#[AsJsonApiResource(tags: …)]` refs,
     * else the humanized-type default — the byte-compatible twin of the bundle's
     * `typeTags($explicit, $type)`.
     *
     * @param list<string> $explicit
     *
     * @return list<string>
     */
    private function typeTags(array $explicit, string $type): array
    {
        return $explicit !== [] ? $explicit : [$this->tagNames->defaultFor($type)];
    }

    /**
     * The document-root tag definitions: the config definitions (authoritative, in
     * config order) unioned with a name-only synthesized {@see Tag} for every tag a
     * type/action references but config did not define, in discovery order.
     *
     * @param list<TypeMetadataInterface> $types
     *
     * @return list<Tag>
     */
    private function tagDefinitions(ServerDocumentConfig $config, array $types): array
    {
        $definitions = [];
        $seen = [];
        foreach ($config->tagDefinitions as $tag) {
            if (!\in_array($tag->name, $seen, true)) {
                $definitions[] = $tag;
                $seen[] = $tag->name;
            }
        }

        foreach ($this->referencedTagNames($types) as $name) {
            if (!\in_array($name, $seen, true)) {
                $definitions[] = new Tag($name);
                $seen[] = $name;
            }
        }

        return $definitions;
    }

    /**
     * Every tag name referenced by a type or its actions, in discovery order (type
     * order, then per-type the type's tags, then each action's tags).
     *
     * @param list<TypeMetadataInterface> $types
     *
     * @return list<string>
     */
    private function referencedTagNames(array $types): array
    {
        $names = [];
        foreach ($types as $type) {
            foreach ($type->tags() as $name) {
                if (!\in_array($name, $names, true)) {
                    $names[] = $name;
                }
            }
            foreach ($type->actions() as $action) {
                foreach ($action->tags() as $name) {
                    if (!\in_array($name, $names, true)) {
                        $names[] = $name;
                    }
                }
            }
        }

        return $names;
    }

    /**
     * The {@see Id} field of `$resource`, or `null` when the resource is absent or
     * declares no id field.
     */
    private function idFieldFor(?\haddowg\JsonApi\Resource\AbstractResource $resource): ?Id
    {
        if ($resource === null) {
            return null;
        }

        foreach ($resource->fields() as $field) {
            if ($field instanceof Id) {
                return $field;
            }
        }

        return null;
    }

    /**
     * @return list<\haddowg\JsonApi\OpenApi\Server>
     */
    private function defaultServers(Server $server): array
    {
        $baseUri = $server->baseUri();

        return $baseUri === '' ? [] : [new \haddowg\JsonApi\OpenApi\Server($baseUri)];
    }

    private function defaultTitle(string $serverName): string
    {
        return $serverName === ServerRegistry::DEFAULT_SERVER
            ? 'JSON:API'
            : \sprintf('JSON:API (%s)', $serverName);
    }
}
