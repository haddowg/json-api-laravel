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
 * `operations` is the exposed operation allow-list. A standalone serializer is
 * **fetch-only**: only {@see Operation::FetchCollection} (`GET /{type}`) and
 * {@see Operation::FetchOne} (`GET /{type}/{id}`) are routable — a resource-less type
 * declares no fields, writes or relations, so a write/relation case in the allow-list is
 * ignored (no route emitted). An empty array means the default — **no** endpoints
 * (serialize-only) — the deliberate asymmetry against an `AbstractResource`, which
 * defaults to all five.
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
     * @param list<Operation>          $operations the exposed operation allow-list; only FetchCollection/FetchOne are routable (empty = none, serialize-only)
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
