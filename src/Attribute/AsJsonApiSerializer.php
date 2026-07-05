<?php

declare(strict_types=1);

namespace haddowg\JsonApiLaravel\Attribute;

use haddowg\JsonApiLaravel\Operation\Operation;

/**
 * Registers the annotated {@see \haddowg\JsonApi\Serializer\SerializerInterface} as
 * the serializer for a JSON:API `type`, **without** an
 * {@see \haddowg\JsonApi\Resource\AbstractResource} (the Laravel twin of the bundle's
 * ADR 0024). A type registered this way is **serialize-only** by default: it renders as
 * primary data, linkage and `included`, but exposes no endpoints of its own — the
 * classic embedded / reference type that only ever appears inside another resource.
 *
 * `AbstractResource` is the preferred sugar (it supplies serializer + hydrator +
 * relations + the fields DSL from one declaration); this is the decoupled path for a
 * type whose wire shape is fully hand-written, or that has no resource at all. Pair it
 * with a provider (via `JsonApi::provider()` or discovery) to make the type fetchable.
 *
 * `operations` is the exposed operation allow-list. An empty array means the default —
 * **no** endpoints (serialize-only) — the deliberate asymmetry against an
 * `AbstractResource`, which defaults to all five. The two fetch cases open the read
 * routes (pair the type with a data provider); the write cases open the write routes,
 * but {@see Operation::Create}/{@see Operation::Update} additionally require the type's
 * decoupled write half — a standalone {@see AsJsonApiHydrator} — or route registration
 * refuses loudly ({@see Operation::Delete} hydrates nothing, so it needs only a
 * persister). A resource-less type declares no relations, so it never gets the relation
 * or relationship-mutation routes an `AbstractResource` does.
 *
 * `server` names the server(s) this type is exposed on: a single server name, a list of
 * names (the same type may join several servers at once), or `null` for the implicit
 * `default` server.
 *
 * `tags` declares the **OpenAPI tag names** every operation of this standalone type is
 * grouped under in the generated document. An empty array means the default: a single
 * tag named the humanized-type form. Tags carry no JSON:API meaning.
 */
#[\Attribute(\Attribute::TARGET_CLASS)]
final readonly class AsJsonApiSerializer
{
    /**
     * @param list<Operation>          $operations the exposed operation allow-list (empty = none, serialize-only); Create/Update require a standalone {@see AsJsonApiHydrator} for the type
     * @param string|list<string>|null $server     the server name(s) exposing this type (null = the implicit `default`)
     * @param list<string>             $tags       the OpenAPI tag names this type is grouped under (empty = the humanized-type default)
     */
    public function __construct(
        public string $type,
        public array $operations = [],
        public string|array|null $server = null,
        public array $tags = [],
    ) {}
}
