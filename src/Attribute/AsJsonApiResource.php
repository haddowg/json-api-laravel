<?php

declare(strict_types=1);

namespace haddowg\JsonApiLaravel\Attribute;

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
    /**
     * @param string|list<string>|null   $server       the server name(s) exposing this type (null = the implicit `default`)
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
     */
    public function __construct(
        public ?string $type = null,
        public string|array|null $server = null,
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
    ) {
        if ($readOnly && $operations !== []) {
            throw new \LogicException(
                'AsJsonApiResource declares both readOnly: true and a non-empty operations list; '
                . 'they are mutually exclusive — drop one. Use readOnly for the two fetch operations, '
                . 'or operations for a precise allow-list.',
            );
        }
    }
}
