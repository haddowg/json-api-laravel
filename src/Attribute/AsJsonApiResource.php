<?php

declare(strict_types=1);

namespace haddowg\JsonApiLaravel\Attribute;

use haddowg\JsonApi\OpenApi\Metadata\CreateResponse;
use haddowg\JsonApi\OpenApi\Metadata\DeleteResponse;
use haddowg\JsonApi\OpenApi\Metadata\FetchCollectionResponse;
use haddowg\JsonApi\OpenApi\Metadata\FetchOneResponse;
use haddowg\JsonApi\OpenApi\Metadata\OperationResponseInterface;
use haddowg\JsonApi\OpenApi\Metadata\OperationResponses;
use haddowg\JsonApi\OpenApi\Metadata\OperationType;
use haddowg\JsonApi\OpenApi\Metadata\UpdateResponse;
use haddowg\JsonApiLaravel\Operation\Operation;

/**
 * Optional metadata for a JSON:API resource. Discovery is zero-config by default
 * (any {@see \haddowg\JsonApi\Resource\AbstractResource} under a scanned path is
 * auto-registered); this attribute is only needed to carry extras — assigning the
 * resource to one or more named servers, overriding the exposed operation set, or
 * marking a type read-only.
 *
 * `server` names the server(s) this resource is exposed on: a single server name,
 * a list of names (the same type may join several servers at once), or `null` for
 * the implicit `default` server.
 *
 * The resource `type` is normally read from the class's static `$type`; the optional
 * `type` here is a declaration-site override for the rare case that differs.
 *
 * `model` names the Eloquent model this type maps to for the reference
 * [Eloquent data layer]{@see \haddowg\JsonApiLaravel\DataProvider\Eloquent\EloquentDataProvider}
 * — the twin of the Symfony bundle's `entity:` (read by its `DoctrineEntityMapPass`).
 * It is the middle tier of the three-tier model resolution (ADR 0019): an explicit
 * `JsonApi::provider()`/`persister()` registration shadows it, and a type declaring
 * neither falls back to the convention guess (`albums` → `App\Models\Album` under the
 * configurable `jsonapi.eloquent.model_namespace`). Declare it when the type and model
 * names diverge — including the "two types, one model" pattern, where convention cannot
 * guess the shared model.
 *
 * `serializer` / `hydrator` override how this type is serialized / hydrated: a
 * resource declares a custom {@see \haddowg\JsonApi\Serializer\SerializerInterface}
 * and/or {@see \haddowg\JsonApi\Hydrator\HydratorInterface} (each container-constructed,
 * so it may have constructor dependencies) when the field DSL cannot express the wire
 * shape. The generic CRUD engine then drives reads/writes for the type through the
 * override instead of the resource's field inventory, while the *other* concern stays
 * field-driven — the twin of the Symfony bundle's escape hatch (its ADR 0023; ADR 0015
 * here).
 *
 * `operations` is the exposed operation allow-list: the {@see Operation} cases this
 * type serves, one route emitted per case. An empty array means the default — all
 * five operations.
 *
 * `readOnly` is an intent-named shorthand for the common "suppress every write"
 * case: `readOnly: true` restricts the type to the two fetch operations
 * ({@see Operation::FetchCollection} and {@see Operation::FetchOne}) without
 * importing the enum. It is mutually exclusive with a non-empty `operations` list;
 * declaring both is a constructor {@see \LogicException}.
 *
 * **Authorization** (PLAN decision 7, policy-first). By default a type is authorized
 * through the model's Gate-registered policy at each lifecycle point
 * (list→`viewAny`, read→`view`, create→`create`, update→`update`, delete→`delete`);
 * a type with no policy is inert (no check). Two additive overrides tune this:
 *  - `policy` names a dedicated API policy class invoked directly (container-resolved,
 *    honouring its `before()`), leaving the application's `Gate::policy()` mapping
 *    untouched — the provider-agnostic seam a POPO-backed type uses.
 *  - `abilities` renames the ability for one or more operations, keyed by
 *    {@see Operation} case value: a `string` is the Gate ability name checked for that
 *    operation (so `Gate::define()` works too), `false` disables the check for that
 *    operation entirely, and `true` documents the operation as secured (an external
 *    firewall/middleware enforces it) WITHOUT the package's Gate enforcing it — projected
 *    into the OpenAPI document as secured + `401`, the byte-compat twin of the bundle's
 *    documentation-only security marker.
 *
 * **Response headers** (PLAN decision 12, declarative cache + RFC 8594
 * deprecation/sunset). `cacheHeaders` declares HTTP cache directives for the type's
 * safe (`GET`) reads — `max_age`/`s_maxage`/`public`/`private`/`no_cache`/
 * `must_revalidate`/`vary` — with an optional nested `operations` map keying a
 * per-read-shape override (`collection`/`read`/`related`/`relationship`) over the
 * resource-level directives. They layer over the global
 * `jsonapi.defaults.cache_headers` and are applied only to a successful `GET`
 * (never a write or an error). `deprecation`/`sunset`/`sunsetLink` declare the IETF
 * Deprecation header field + RFC 8594 `Sunset` (+ its companion `Link`), emitted on
 * **every** response for the type (reads and writes alike): `deprecation: true`
 * emits a bare `Deprecation: true`, `deprecation: '<date>'` the date; `sunset` the
 * `Sunset` HTTP-date; `sunsetLink` the companion sunset `Link`.
 *
 * **OpenAPI tags** (PLAN decision 11). `tags` declares the OpenAPI tag names the type's
 * operations are grouped under; an empty list falls back to the humanized-type default
 * ({@see \haddowg\JsonApiLaravel\OpenApi\Metadata\TagNameResolver}). A custom action that
 * declares no tags of its own inherits the mount type's explicit tags (then the humanized
 * default) — the same resolution the Symfony bundle applies — so the projected document is
 * byte-compatible with the bundle's for an identically-tagged domain.
 *
 * Further metadata (OpenAPI descriptions) is added in later phases, mirroring the
 * Symfony bundle's attribute.
 */
#[\Attribute(\Attribute::TARGET_CLASS)]
final readonly class AsJsonApiResource
{
    /** @var list<OperationResponseInterface> the resolved create success-response override (empty = the default `201`) */
    public array $create;

    /** @var list<OperationResponseInterface> the resolved update success-response override (empty = the default `200`) */
    public array $update;

    /** @var list<OperationResponseInterface> the resolved delete success-response override (empty = the default `204`) */
    public array $delete;

    /** @var list<OperationResponseInterface> the resolved fetch-one success-response override (empty = the default `200`) */
    public array $fetchOne;

    /** @var list<OperationResponseInterface> the resolved fetch-collection success-response override (empty = the default `200`) */
    public array $fetchCollection;

    /** @var SoftDeletes|null the resolved soft-delete configuration (null = not soft-deletable); `softDeletes: true` normalises to a default {@see SoftDeletes} */
    public ?SoftDeletes $softDeletes;

    /**
     * @param string|list<string>|null   $server       the server name(s) exposing this type (null = the implicit `default`)
     * @param class-string<\Illuminate\Database\Eloquent\Model>|null $model the Eloquent model this type maps to for the reference Eloquent layer (null = the convention guess under `jsonapi.eloquent.model_namespace`)
     * @param class-string<\haddowg\JsonApi\Serializer\SerializerInterface>|null $serializer a custom serializer for this type (container-constructed); reads render through it while writes stay field-driven
     * @param class-string<\haddowg\JsonApi\Hydrator\HydratorInterface>|null     $hydrator   a custom hydrator for this type (container-constructed); writes hydrate through it while reads stay field-driven
     * @param list<Operation>            $operations   the exposed operation allow-list (empty = all five); mutually exclusive with `readOnly`
     * @param bool                       $readOnly     shorthand restricting the type to the two fetch operations; mutually exclusive with a non-empty `operations`
     * @param class-string|null          $policy       a dedicated API policy class invoked directly for every operation (null = the model's Gate-registered policy, or inert if none)
     * @param array<string, string|bool> $abilities   per-operation ability override keyed by {@see Operation} case value: a string renames the Gate ability, `false` disables the check, `true` documents it as secured (external enforcement) without the package's Gate enforcing it
     * @param array<string, mixed>       $cacheHeaders declarative HTTP cache directives for GET reads (`max_age`/`s_maxage`/`public`/`private`/`no_cache`/`must_revalidate`/`vary`), with an optional nested `operations` per-read-shape override map; empty = none
     * @param bool|string|null           $deprecation  IETF Deprecation-header deprecation: `true` (bare header), a date string (`Deprecation: <date>`), or null (none)
     * @param string|null                $sunset       RFC 8594 sunset HTTP-date (`Sunset: <date>`), or null
     * @param string|null                $sunsetLink   a URI for the companion `Link: <uri>; rel="sunset"` (emitted only when `sunset` is set)
     * @param list<string>               $tags         the OpenAPI tag names this type's operations are grouped under (empty = the humanized-type default)
     * @param CreateResponse|list<CreateResponse>|null                   $create          the create (`POST`) success response(s) this type advertises (null = the default `201`); use {@see \haddowg\JsonApi\OpenApi\Metadata\Accepted} for an async `202`, {@see \haddowg\JsonApi\OpenApi\Metadata\NoContent} for a client-id `204`
     * @param UpdateResponse|list<UpdateResponse>|null                   $update          the update (`PATCH`) success response(s) (null = the default `200`)
     * @param DeleteResponse|list<DeleteResponse>|null                   $delete          the delete (`DELETE`) success response(s) (null = the default `204`)
     * @param FetchOneResponse|list<FetchOneResponse>|null               $fetchOne        the fetch-one (`GET /{type}/{id}`) success response(s) (null = the default `200`); use {@see \haddowg\JsonApi\OpenApi\Metadata\SeeOther} for an async-completion `303`
     * @param FetchCollectionResponse|list<FetchCollectionResponse>|null $fetchCollection the fetch-collection (`GET /{type}`) success response(s) (null = the default `200`)
     * @param bool|SoftDeletes                                           $softDeletes     opt this type into first-class soft deletes: `true` (or a configured {@see SoftDeletes}) synthesizes the `restore`/`force-delete` actions (`DELETE` stays a recoverable soft delete); `false` (the default) leaves the type unaffected
     */
    public function __construct(
        public ?string $type = null,
        public string|array|null $server = null,
        public ?string $model = null,
        public ?string $serializer = null,
        public ?string $hydrator = null,
        public array $operations = [],
        public bool $readOnly = false,
        public ?string $policy = null,
        public array $abilities = [],
        public array $cacheHeaders = [],
        public bool|string|null $deprecation = null,
        public ?string $sunset = null,
        public ?string $sunsetLink = null,
        public array $tags = [],
        CreateResponse|array|null $create = null,
        UpdateResponse|array|null $update = null,
        DeleteResponse|array|null $delete = null,
        FetchOneResponse|array|null $fetchOne = null,
        FetchCollectionResponse|array|null $fetchCollection = null,
        bool|SoftDeletes $softDeletes = false,
    ) {
        if ($readOnly && $operations !== []) {
            throw new \LogicException(
                'AsJsonApiResource declares both readOnly: true and a non-empty operations list; '
                . 'they are mutually exclusive — drop one. Use readOnly for the two fetch operations, '
                . 'or operations for a precise allow-list.',
            );
        }

        $this->create = self::normalizeResponses(OperationType::Create, $create);
        $this->update = self::normalizeResponses(OperationType::Update, $update);
        $this->delete = self::normalizeResponses(OperationType::Delete, $delete);
        $this->fetchOne = self::normalizeResponses(OperationType::FetchOne, $fetchOne);
        $this->fetchCollection = self::normalizeResponses(OperationType::FetchCollection, $fetchCollection);

        // `softDeletes: true` is shorthand for a default configuration; `false` opts out.
        $this->softDeletes = match ($softDeletes) {
            false => null,
            true => new SoftDeletes(),
            default => $softDeletes,
        };

        $this->assertResponsesExposed($operations, $readOnly);
    }

    /**
     * Normalises a declared response override into a validated list: a single response
     * object becomes a one-element list, `null` an empty list (the operation keeps its
     * default). A non-empty set is validated by core's {@see OperationResponses} — spec-valid
     * status codes only, no duplicates, at most one asynchronous `202` — so an illegal
     * declaration fails loudly at discovery rather than producing a malformed document.
     *
     * @param OperationResponseInterface|list<OperationResponseInterface>|null $declared
     *
     * @return list<OperationResponseInterface>
     */
    private static function normalizeResponses(OperationType $operation, OperationResponseInterface|array|null $declared): array
    {
        if ($declared === null) {
            return [];
        }

        $responses = $declared instanceof OperationResponseInterface ? [$declared] : \array_values($declared);
        foreach ($responses as $response) {
            if (!$response instanceof OperationResponseInterface) {
                throw new \InvalidArgumentException(\sprintf(
                    'Every %s response override must implement %s.',
                    $operation->value,
                    OperationResponseInterface::class,
                ));
            }
        }

        OperationResponses::validate($operation, $responses);

        return $responses;
    }

    /**
     * Guards that a response override is declared only for an exposed operation: a
     * `create`/`update`/`delete` override on a `readOnly` type, or any override for an
     * operation absent from a non-empty `operations` allow-list, is a configuration error
     * rather than a silently ignored declaration.
     *
     * @param list<Operation> $operations
     */
    private function assertResponsesExposed(array $operations, bool $readOnly): void
    {
        $exposed = $readOnly
            ? [Operation::FetchCollection->value, Operation::FetchOne->value]
            : ($operations !== []
                ? \array_map(static fn(Operation $op): string => $op->value, $operations)
                : \array_map(static fn(Operation $op): string => $op->value, Operation::cases()));

        foreach ([
            Operation::Create->value => $this->create,
            Operation::Update->value => $this->update,
            Operation::Delete->value => $this->delete,
            Operation::FetchOne->value => $this->fetchOne,
            Operation::FetchCollection->value => $this->fetchCollection,
        ] as $operation => $responses) {
            if ($responses !== [] && !\in_array($operation, $exposed, true)) {
                throw new \LogicException(\sprintf(
                    'AsJsonApiResource declares a response override for the %s operation, but it is not exposed; '
                    . 'add it to `operations` (or drop `readOnly`).',
                    $operation,
                ));
            }
        }
    }
}
