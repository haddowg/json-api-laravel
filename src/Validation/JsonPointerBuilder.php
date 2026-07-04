<?php

declare(strict_types=1);

namespace haddowg\JsonApiLaravel\Validation;

/**
 * Builds the JSON:API `source.pointer` (RFC 6901) a validation error carries from an
 * `illuminate/validation` error key. Core has no such helper — it only offers the
 * `ErrorSource::fromPointer(string)` sink — so the bridge owns the mapping.
 *
 * The bridge validates a resource's `attributes` array, so an error key is Laravel's
 * dot-notation attribute path (`title`, `address.postcode` for a nested
 * {@see \haddowg\JsonApi\Resource\Field\Map}, `tags.0` for an array element); each
 * dot-segment becomes a reference token under `/data/attributes`. An empty key (a
 * document-level violation) points at `/data/attributes` itself.
 */
final class JsonPointerBuilder
{
    private const string ATTRIBUTES_BASE = '/data/attributes';

    /**
     * The pointer for a violation on the resource `attributes`, from Laravel's
     * dot-notation error key (`title`, `address.postcode`, `tags.0`, or `''`).
     */
    public function forAttribute(string $key): string
    {
        if ($key === '') {
            return self::ATTRIBUTES_BASE;
        }

        $segments = \array_map(
            [$this, 'encodeSegment'],
            \explode('.', $key),
        );

        return self::ATTRIBUTES_BASE . '/' . \implode('/', $segments);
    }

    /**
     * The pointer for a violation on a relationship **linkage** `type` in a
     * whole-resource write body: the to-one linkage points at
     * `/data/relationships/<rel>/data/type`, a to-many member at
     * `/data/relationships/<rel>/data/<index>/type` (the index supplied for a to-many
     * element, omitted for a to-one). Locates the offending linkage when its resource
     * `type` is not an accepted related type of the relation.
     */
    public function forLinkageType(string $relation, ?int $index = null): string
    {
        $base = '/data/relationships/' . $this->encodeSegment($relation) . '/data';
        if ($index !== null) {
            $base .= '/' . $index;
        }

        return $base . '/type';
    }

    /**
     * The pointer for a violation on a relationship **linkage** id in a whole-resource write
     * body: the to-one linkage points at `/data/relationships/<rel>/data/id`, a to-many member
     * at `/data/relationships/<rel>/data/<index>/id`. Locates a linkage whose id violates the
     * related type's declared id format.
     */
    public function forLinkageId(string $relation, ?int $index = null): string
    {
        $base = '/data/relationships/' . $this->encodeSegment($relation) . '/data';
        if ($index !== null) {
            $base .= '/' . $index;
        }

        return $base . '/id';
    }

    /**
     * The pointer for a violation on a relationship linkage member's pivot `meta` field in a
     * **whole-resource write**: the to-one member points at
     * `/data/relationships/<rel>/data/meta/pivot/<field>`, a to-many member at
     * `/data/relationships/<rel>/data/<index>/meta/pivot/<field>` (the index omitted for a
     * to-one). Pivot values nest under `meta.pivot` (symmetric with reads); an empty `$field`
     * (a member-level violation) yields `…/meta/pivot`.
     */
    public function forLinkageMeta(string $relation, string $field, ?int $index = null): string
    {
        $base = '/data/relationships/' . $this->encodeSegment($relation) . '/data';
        if ($index !== null) {
            $base .= '/' . $index;
        }
        $base .= '/meta/pivot';

        return $field === '' ? $base : $base . '/' . $this->encodeSegment($field);
    }

    /**
     * The pointer for a violation on a linkage `type` at a **relationship-mutation
     * endpoint** (`PATCH`/`POST`/`DELETE …/relationships/<rel>`), where the request body
     * root *is* the relationship object: a to-one points at `/data/type`, a to-many member
     * at `/data/<index>/type` (the index omitted for a to-one).
     */
    public function forRelationshipEndpointLinkageType(?int $index = null): string
    {
        return $index === null ? '/data/type' : '/data/' . $index . '/type';
    }

    /**
     * The pointer for a violation on a linkage id at a **relationship-mutation endpoint**
     * (`PATCH`/`POST`/`DELETE …/relationships/<rel>`), where the request body root *is* the
     * relationship object: a to-one points at `/data/id`, a to-many member at
     * `/data/<index>/id` (the index omitted for a to-one).
     */
    public function forRelationshipEndpointLinkageId(?int $index = null): string
    {
        return $index === null ? '/data/id' : '/data/' . $index . '/id';
    }

    /**
     * The pointer for a violation on a linkage member's pivot `meta` field at a
     * **relationship-mutation endpoint** (`PATCH`/`POST …/relationships/<rel>`), where the
     * request body root *is* the relationship object: a to-one member points at
     * `/data/meta/pivot/<field>`, a to-many member at `/data/<index>/meta/pivot/<field>`
     * (the index omitted for a to-one). Writable pivot values nest under `meta.pivot`
     * (symmetric with how pivot renders on reads); an empty `$field` (a member-level
     * violation) yields `…/meta/pivot`.
     */
    public function forRelationshipEndpointLinkageMeta(string $field, ?int $index = null): string
    {
        $base = ($index === null ? '/data' : '/data/' . $index) . '/meta/pivot';

        return $field === '' ? $base : $base . '/' . $this->encodeSegment($field);
    }

    /**
     * Escapes a single JSON Pointer reference token per RFC 6901: `~` → `~0`,
     * `/` → `~1`.
     */
    private function encodeSegment(string $segment): string
    {
        return \str_replace(['~', '/'], ['~0', '~1'], $segment);
    }
}
