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
     * Escapes a single JSON Pointer reference token per RFC 6901: `~` → `~0`,
     * `/` → `~1`.
     */
    private function encodeSegment(string $segment): string
    {
        return \str_replace(['~', '/'], ['~0', '~1'], $segment);
    }
}
